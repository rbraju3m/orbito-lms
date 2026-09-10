<?php

declare(strict_types=1);

use App\Domain\Analytics\Actions\BuildDailyRollups;
use App\Domain\Analytics\Models\DailyCourseStat;
use App\Domain\Catalog\Actions\ChangeBundleStatus;
use App\Domain\Catalog\Enums\BundleStatus;
use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Actions\CapturePayment;
use App\Domain\Commerce\Actions\InitiatePayment;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Actions\SetProductPrice;
use App\Domain\Commerce\Actions\SyncBundleProduct;
use App\Domain\Commerce\Actions\SyncCourseProduct;
use App\Domain\Commerce\Data\WebhookEvent;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Enrollment\Actions\EnrollInCourse;
use App\Domain\Enrollment\Data\EnrollmentIntent;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;

/*
 * Buying a bundle: what it grants, and where the money is recorded.
 *
 * The gateway half is already proven in MoneyPathTest. What is tested here is
 * everything a bundle adds — the fan-out, the prerequisite exemption, and the
 * allocation that keeps per-course revenue and the platform total the same
 * number.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->currency = strtoupper((string) config('orbito.currency.base'));
    $this->admin = userWithRole(RoleKey::Admin);
    $this->learner = User::factory()->withRole(RoleKey::Student)->create();

    PaymentGatewayAccount::create([
        'gateway' => Gateway::Fake,
        'credentials' => ['key' => 'sk_test_bundle'],
        'webhook_secret' => 'whsec_bundle',
        'is_active' => true,
    ]);
});

/** A published, priced course. */
function bundleCourse(int $minor, string $currency, ?string $title = null): Course
{
    $course = courseWithCurriculum(
        Course::factory()->published()->create([
            'pricing_model' => $minor > 0 ? PricingModel::OneTime : PricingModel::Free,
            ...($title !== null ? ['title' => $title] : []),
        ]),
        [1],
    );

    if ($minor > 0) {
        app(SetProductPrice::class)->handle(
            app(SyncCourseProduct::class)->handle($course),
            $currency,
            $minor,
        );
    }

    return $course->fresh(['product.prices']) ?? $course;
}

/** A published bundle at $minor, containing $courses. */
function publishedBundle(array $courses, int $minor, string $currency): Bundle
{
    $bundle = Bundle::factory()->containing($courses)->create();

    app(SetProductPrice::class)->handle(
        app(SyncBundleProduct::class)->handle($bundle),
        $currency,
        $minor,
    );

    return app(ChangeBundleStatus::class)->handle($bundle, BundleStatus::Published);
}

/** Buys it outright: order placed, payment captured, access granted. */
function buyBundle(User $user, Bundle $bundle, string $currency): Order
{
    $cart = Cart::create(['user_id' => $user->id, 'currency' => $currency]);
    $cart->items()->create(['product_id' => $bundle->product->id]);

    $order = app(PlaceOrder::class)->handle($user, $cart->load('items.product.prices'));
    app(InitiatePayment::class)->handle($order, Gateway::Fake);

    // The webhook path is proven elsewhere; this drives the same action.
    $payment = $order->payments()->firstOrFail();
    app(CapturePayment::class)->handle(
        $payment,
        new WebhookEvent(
            id: 'evt_'.$order->id,
            type: 'payment.captured',
            externalPaymentId: $payment->external_id,
            amountMinor: $payment->amount_minor,
            currency: $payment->currency,
            payload: [],
        ),
    );

    return $order->refresh();
}

/* ------------------------------------------------------------- the grant */

it('grants every course in the bundle, marked as coming from one', function (): void {
    $courses = [bundleCourse(2000, $this->currency), bundleCourse(4000, $this->currency)];
    $bundle = publishedBundle($courses, 4500, $this->currency);

    $order = buyBundle($this->learner, $bundle, $this->currency);

    expect($order->status)->toBe(OrderStatus::Paid);

    foreach ($courses as $course) {
        $enrollment = Enrollment::where('user_id', $this->learner->id)
            ->where('course_id', $course->id)
            ->first();

        expect($enrollment)->not->toBeNull()
            ->and($enrollment->source)->toBe(EnrollmentSource::Bundle);
    }
});

/*
 * The most natural bundle there is: a curated path where course 2 builds on
 * course 1. Enforcing prerequisites would leave the buyer paid-up and locked
 * out of half of what they bought.
 */
it('grants a course whose prerequisite is inside the same bundle', function (): void {
    $first = bundleCourse(2000, $this->currency);
    $second = bundleCourse(4000, $this->currency);
    $second->prerequisites()->attach($first->id, ['position' => 0]);

    $bundle = publishedBundle([$first, $second], 4500, $this->currency);

    buyBundle($this->learner, $bundle, $this->currency);

    expect(Enrollment::where('user_id', $this->learner->id)->count())->toBe(2);
});

/* ------------------------------------------------------- already owned */

it('sells a bundle the buyer only partly owns', function (): void {
    $courses = [bundleCourse(2000, $this->currency), bundleCourse(4000, $this->currency)];
    app(EnrollInCourse::class)->handle(
        $this->learner,
        $courses[0],
        EnrollmentIntent::manual($this->admin->id),
    );

    $bundle = publishedBundle($courses, 4500, $this->currency);
    $order = buyBundle($this->learner, $bundle, $this->currency);

    expect($order->status)->toBe(OrderStatus::Paid)
        // The one they already had is untouched; the other is now theirs.
        ->and(Enrollment::where('user_id', $this->learner->id)->count())->toBe(2);
});

it('refuses a bundle the buyer already owns entirely', function (): void {
    $courses = [bundleCourse(2000, $this->currency), bundleCourse(4000, $this->currency)];

    foreach ($courses as $course) {
        app(EnrollInCourse::class)->handle(
            $this->learner,
            $course,
            EnrollmentIntent::manual($this->admin->id),
        );
    }

    $bundle = publishedBundle($courses, 4500, $this->currency);

    $cart = Cart::create(['user_id' => $this->learner->id, 'currency' => $this->currency]);
    $cart->items()->create(['product_id' => $bundle->product->id]);

    expect(fn () => app(PlaceOrder::class)->handle($this->learner, $cart->load('items.product.prices')))
        ->toThrow(CheckoutRejected::class);
});

/* ------------------------------------------------------- the allocation */

it('splits the bundle price across its courses, summing exactly to the line', function (): void {
    $courses = [bundleCourse(2000, $this->currency), bundleCourse(4000, $this->currency)];
    $bundle = publishedBundle($courses, 4500, $this->currency);

    $order = buyBundle($this->learner, $bundle, $this->currency);
    $line = $order->items()->firstOrFail();
    $allocations = $line->allocations()->pluck('amount_minor', 'course_id');

    // 6000 of courses sold for 4500: a third and two thirds.
    expect($allocations[$courses[0]->id])->toBe(1500)
        ->and($allocations[$courses[1]->id])->toBe(3000)
        ->and($allocations->sum())->toBe($line->total_minor);
});

/* Nothing may round away. 100 across three equal courses is 34/33/33. */
it('never loses a minor unit to rounding', function (): void {
    $courses = [
        bundleCourse(1000, $this->currency),
        bundleCourse(1000, $this->currency),
        bundleCourse(1000, $this->currency),
    ];
    $bundle = publishedBundle($courses, 100, $this->currency);

    $line = buyBundle($this->learner, $bundle, $this->currency)->items()->firstOrFail();

    expect((int) $line->allocations()->sum('amount_minor'))->toBe(100)
        ->and($line->total_minor)->toBe(100);
});

it('allocates nothing to a free course in a bundle', function (): void {
    $paid = bundleCourse(4000, $this->currency);
    $free = bundleCourse(0, $this->currency);
    $bundle = publishedBundle([$paid, $free], 3000, $this->currency);

    $line = buyBundle($this->learner, $bundle, $this->currency)->items()->firstOrFail();
    $allocations = $line->allocations()->pluck('amount_minor', 'course_id');

    expect($allocations[$free->id])->toBe(0)
        ->and($allocations[$paid->id])->toBe(3000);
});

/* A course line needs no allocation: the line IS the attribution. */
it('writes no allocations for a course bought directly', function (): void {
    $course = bundleCourse(4000, $this->currency);

    $cart = Cart::create(['user_id' => $this->learner->id, 'currency' => $this->currency]);
    $cart->items()->create(['product_id' => $course->product->id]);
    $order = app(PlaceOrder::class)->handle($this->learner, $cart->load('items.product.prices'));

    expect($order->items()->firstOrFail()->allocations()->count())->toBe(0);
});

/* --------------------------------------------------------- the reporting */

it('counts bundle money in the per-course revenue an instructor sees', function (): void {
    $courses = [bundleCourse(2000, $this->currency), bundleCourse(4000, $this->currency)];
    $bundle = publishedBundle($courses, 4500, $this->currency);

    buyBundle($this->learner, $bundle, $this->currency);

    app(BuildDailyRollups::class)->handle(CarbonImmutable::now()->startOfDay());

    $revenue = DailyCourseStat::query()
        ->whereIn('course_id', array_map(fn (Course $c): int => $c->id, $courses))
        ->pluck('revenue_minor', 'course_id');

    expect((int) $revenue[$courses[0]->id])->toBe(1500)
        ->and((int) $revenue[$courses[1]->id])->toBe(3000)
        // And the parts still sum to what was actually charged.
        ->and($revenue->sum())->toBe(4500);
});

it('keeps direct and bundle revenue for the same course in one figure', function (): void {
    $shared = bundleCourse(2000, $this->currency);
    $other = bundleCourse(4000, $this->currency);
    $bundle = publishedBundle([$shared, $other], 4500, $this->currency);

    // One learner buys the bundle; another buys the same course outright.
    buyBundle($this->learner, $bundle, $this->currency);

    $second = User::factory()->withRole(RoleKey::Student)->create();
    $cart = Cart::create(['user_id' => $second->id, 'currency' => $this->currency]);
    $cart->items()->create(['product_id' => $shared->product->id]);
    $order = app(PlaceOrder::class)->handle($second, $cart->load('items.product.prices'));
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    app(BuildDailyRollups::class)->handle(CarbonImmutable::now()->startOfDay());

    // 1500 allocated from the bundle + 2000 bought directly.
    expect((int) DailyCourseStat::where('course_id', $shared->id)->value('revenue_minor'))
        ->toBe(3500);
});
