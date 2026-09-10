<?php

declare(strict_types=1);

use App\Domain\Analytics\Actions\BuildDailyRollups;
use App\Domain\Analytics\Models\DailyCourseStat;
use App\Domain\Analytics\Models\DailyPlatformStat;
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
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;

/*
 * The failure docs/FEATURE_MATRIX.md J9 predicted: per-course revenue is summed
 * from order LINES and bundle ALLOCATIONS, the platform figure from the ORDER
 * total. A discount taken off the order and not off its lines would make them
 * disagree by exactly the discount. It is split across the lines, and a
 * discounted bundle line is then split across its courses at its NET total,
 * so the three still agree to the minor unit.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->currency = strtoupper((string) config('orbito.currency.base'));

    PaymentGatewayAccount::create([
        'gateway' => Gateway::Fake,
        'credentials' => ['key' => 'sk_test_coupon_revenue'],
        'webhook_secret' => 'whsec_coupon_revenue',
        'is_active' => true,
    ]);
});

function couponRevenueCourse(int $minor, string $currency): Course
{
    $course = courseWithCurriculum(
        Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]),
        [1],
    );

    app(SetProductPrice::class)->handle(app(SyncCourseProduct::class)->handle($course), $currency, $minor);

    return $course->fresh(['product.prices']) ?? $course;
}

it('keeps course revenue equal to the platform total when an order is discounted', function (): void {
    $inBundleA = couponRevenueCourse(2_000, $this->currency);
    $inBundleB = couponRevenueCourse(4_000, $this->currency);
    $alone = couponRevenueCourse(3_000, $this->currency);

    $bundle = Bundle::factory()->containing([$inBundleA, $inBundleB])->create();
    app(SetProductPrice::class)->handle(app(SyncBundleProduct::class)->handle($bundle), $this->currency, 4_500);
    $bundle = app(ChangeBundleStatus::class)->handle($bundle, BundleStatus::Published);

    // An awkward amount on purpose: 1001 does not divide evenly anywhere.
    $coupon = Coupon::factory()->fixed(1_001, $this->currency)->create();
    $learner = User::factory()->withRole(RoleKey::Student)->create();

    $cart = Cart::create(['user_id' => $learner->id, 'currency' => $this->currency, 'coupon_id' => $coupon->id]);
    $cart->items()->create(['product_id' => $bundle->product->id]);
    $cart->items()->create(['product_id' => $alone->product->id]);

    $order = app(PlaceOrder::class)->handle($learner, $cart->load('items.product.prices'));

    expect($order->subtotal_minor)->toBe(7_500)
        ->and($order->discount_minor)->toBe(1_001)
        ->and($order->total_minor)->toBe(6_499)
        ->and($order->items->sum('total_minor'))->toBe(6_499);

    // The bundle's courses share its NET line, exactly.
    $bundleLine = $order->items->firstWhere('purchasable_type', 'bundle');
    expect((int) $bundleLine->allocations()->sum('amount_minor'))->toBe($bundleLine->total_minor);

    app(InitiatePayment::class)->handle($order, Gateway::Fake);
    $payment = $order->payments()->firstOrFail();
    app(CapturePayment::class)->handle($payment, new WebhookEvent(
        id: 'evt_'.$order->id,
        type: 'payment.captured',
        externalPaymentId: $payment->external_id,
        amountMinor: $payment->amount_minor,
        currency: $payment->currency,
        payload: [],
    ));

    app(BuildDailyRollups::class)->handle(CarbonImmutable::now()->startOfDay());

    $platform = DailyPlatformStat::query()->firstOrFail();
    $courses = (int) DailyCourseStat::query()->sum('revenue_minor');

    expect($platform->revenue_minor)->toBe(6_499)
        ->and($courses + $platform->download_revenue_minor)->toBe($platform->revenue_minor);
});
