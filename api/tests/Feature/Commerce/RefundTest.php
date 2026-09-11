<?php

declare(strict_types=1);

use App\Domain\Analytics\Actions\BuildDailyRollups;
use App\Domain\Analytics\Models\DailyCourseStat;
use App\Domain\Analytics\Models\DailyPlatformStat;
use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Actions\CapturePayment;
use App\Domain\Commerce\Actions\InitiatePayment;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Data\WebhookEvent;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Enrollment\Actions\EnrollInCourse;
use App\Domain\Enrollment\Data\EnrollmentIntent;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/*
 * Giving money back. The rules that matter most are the ones about what does
 * NOT happen: a partial refund never touches access, a refund never takes
 * away access that came from somewhere else, and two refunds can never give
 * back more than was paid.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->currency = strtoupper((string) config('orbito.currency.base'));
    $this->admin = userWithRole(RoleKey::Admin);
    $this->learner = User::factory()->withRole(RoleKey::Student)->create();

    $this->gateway = PaymentGatewayAccount::create([
        'gateway' => Gateway::Fake,
        'credentials' => ['key' => 'sk_test_refunds'],
        'webhook_secret' => 'whsec_refunds',
        'is_active' => true,
    ]);

    $this->poetry = courseWithCurriculum(Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]), [1]);
    $this->prose = courseWithCurriculum(Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]), [1]);

    $this->poetryProduct = Product::factory()->pricedAt(4_900, $this->currency)
        ->create(['purchasable_id' => $this->poetry->id, 'title' => 'Poetry']);
    $this->proseProduct = Product::factory()->pricedAt(2_900, $this->currency)
        ->create(['purchasable_id' => $this->prose->id, 'title' => 'Prose']);
});

/** Bought outright: placed, paid through the test gateway, granted. */
function paidOrder(User $learner, array $products, string $currency, ?Coupon $coupon = null): Order
{
    $cart = Cart::create(['user_id' => $learner->id, 'currency' => $currency, 'coupon_id' => $coupon?->id]);

    foreach ($products as $product) {
        $cart->items()->create(['product_id' => $product->id]);
    }

    $order = app(PlaceOrder::class)->handle($learner, $cart->load('items.product.prices'));
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

    return $order->refresh();
}

function refund(User $actor, Order $order, array $body): TestResponse
{
    return test()->actingAs($actor)->postJson("/api/v1/admin/orders/{$order->uuid}/refunds", $body + ['method' => 'gateway']);
}

function accessTo(User $learner, Course $course): ?EnrollmentStatus
{
    return Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->first()?->status;
}

/* -------------------------------------------------------------- a full refund */

it('gives it all back through the gateway and takes away what the order granted', function (): void {
    $order = paidOrder($this->learner, [$this->poetryProduct, $this->proseProduct], $this->currency);

    refund($this->admin, $order, ['amount_minor' => 7_800, 'reason' => 'Bought by mistake.'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.method', 'gateway');

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Refunded)
        ->and($order->refunded_minor)->toBe(7_800)
        ->and(accessTo($this->learner, $this->poetry))->toBe(EnrollmentStatus::Cancelled)
        ->and(accessTo($this->learner, $this->prose))->toBe(EnrollmentStatus::Cancelled);
});

it('leaves access alone on a goodwill refund', function (): void {
    $order = paidOrder($this->learner, [$this->poetryProduct], $this->currency);

    refund($this->admin, $order, ['amount_minor' => 4_900, 'revoke_access' => false])->assertCreated();

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(accessTo($this->learner, $this->poetry))->toBe(EnrollmentStatus::Active);
});

/* Only what THIS order granted. */
it('never takes away access that came from somewhere else', function (): void {
    $order = paidOrder($this->learner, [$this->poetryProduct], $this->currency);
    app(EnrollInCourse::class)->handle($this->learner, $this->prose, EnrollmentIntent::manual($this->admin->id));

    refund($this->admin, $order, ['amount_minor' => 4_900])->assertCreated();

    expect(accessTo($this->learner, $this->poetry))->toBe(EnrollmentStatus::Cancelled)
        ->and(accessTo($this->learner, $this->prose))->toBe(EnrollmentStatus::Active);
});

/* ----------------------------------------------------------- partial refunds */

it('never touches access on a partial refund, and splits it across the lines', function (): void {
    $order = paidOrder($this->learner, [$this->poetryProduct, $this->proseProduct], $this->currency);

    refund($this->admin, $order, ['amount_minor' => 1_000])->assertCreated();

    $order->refresh()->load('refunds.lines');

    expect($order->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and($order->refunded_minor)->toBe(1_000)
        ->and($order->refunds->first()->lines->sum('amount_minor'))->toBe(1_000)
        ->and(accessTo($this->learner, $this->poetry))->toBe(EnrollmentStatus::Active);

    $this->actingAs($this->admin)->getJson("/api/v1/orders/{$order->uuid}")
        ->assertOk()
        ->assertJsonPath('data.refundable_minor', 6_800)
        ->assertJsonPath('data.refunds.0.amount_minor', 1_000);
});

/* The last of several partials is the one that can revoke. */
it('reaches exactly zero on every line through several partial refunds', function (): void {
    $order = paidOrder($this->learner, [$this->poetryProduct, $this->proseProduct], $this->currency);

    refund($this->admin, $order, ['amount_minor' => 3_333])->assertCreated();
    refund($this->admin, $order, ['amount_minor' => 1_111])->assertCreated();
    refund($this->admin, $order, ['amount_minor' => 3_356])->assertCreated();

    $order->refresh()->load('items', 'refunds.lines');
    $given = $order->refunds->flatMap->lines->groupBy('order_item_id')->map->sum('amount_minor');

    $order->items->each(fn ($item) => expect($given[$item->id])->toBe($item->total_minor));
    expect($order->status)->toBe(OrderStatus::Refunded)
        ->and(accessTo($this->learner, $this->poetry))->toBe(EnrollmentStatus::Cancelled);
});

it('refuses to give back more than is left', function (): void {
    $order = paidOrder($this->learner, [$this->poetryProduct], $this->currency);
    refund($this->admin, $order, ['amount_minor' => 4_000])->assertCreated();

    $response = refund($this->admin, $order, ['amount_minor' => 1_000])->assertStatus(422);

    expect($response)->toBeApiError('refund_rejected')
        ->and($response->json('error.meta'))->toBe(['reason' => 'too_much', 'refundable_minor' => 900]);
});

/* ------------------------------------------------------------- how it moves */

it('records a refund made elsewhere without calling the gateway', function (): void {
    $order = paidOrder($this->learner, [$this->poetryProduct], $this->currency);
    $this->gateway->update(['credentials' => ['key' => 'x', 'refund_behaviour' => 'fail']]);

    refund($this->admin, $order, ['amount_minor' => 4_900, 'method' => 'external', 'reason' => 'Refunded by bank transfer.'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.method', 'external');

    expect(Refund::sole()->payment_id)->toBeNull();
});

it('keeps a refund the gateway declined as failed, and frees its amount', function (): void {
    $order = paidOrder($this->learner, [$this->poetryProduct], $this->currency);
    $this->gateway->update(['credentials' => ['key' => 'x', 'refund_behaviour' => 'fail']]);

    expect(refund($this->admin, $order, ['amount_minor' => 4_900])->assertStatus(503))->toBeApiError('gateway_unavailable');

    expect(Refund::sole()->status)->toBe(RefundStatus::Failed)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(accessTo($this->learner, $this->poetry))->toBe(EnrollmentStatus::Active);

    // Nothing was given back, so all of it can still be.
    $this->gateway->update(['credentials' => ['key' => 'x']]);
    refund($this->admin, $order, ['amount_minor' => 4_900])->assertCreated();
});

it('holds the amount while the provider has not settled', function (): void {
    $order = paidOrder($this->learner, [$this->poetryProduct], $this->currency);
    $this->gateway->update(['credentials' => ['key' => 'x', 'refund_behaviour' => 'pending']]);

    refund($this->admin, $order, ['amount_minor' => 4_900])->assertCreated()->assertJsonPath('data.status', 'pending');

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(refund($this->admin, $order, ['amount_minor' => 1, 'method' => 'external'])->assertStatus(422)->json('error.meta.reason'))
        ->toBe('nothing_left');
});

it('refuses to refund an order that was never paid', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    expect(refund($this->admin, $order, ['amount_minor' => 100])->assertStatus(422)->json('error.meta.reason'))->toBe('not_paid');
});

/* ------------------------------------------------------------- coupons */

it('gives a coupon use back when its order is fully refunded — not when partly', function (): void {
    $coupon = Coupon::factory()->percent(10)->create(['max_redemptions' => 1]);
    $order = paidOrder($this->learner, [$this->poetryProduct], $this->currency, $coupon);
    $other = User::factory()->withRole(RoleKey::Student)->create();

    refund($this->admin, $order, ['amount_minor' => 100])->assertCreated();

    $this->actingAs($other)->postJson('/api/v1/cart/items', ['product_id' => $this->proseProduct->uuid])->assertCreated();
    $this->actingAs($other)->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertStatus(422);

    refund($this->admin, $order, ['amount_minor' => $order->refresh()->total_minor - 100])->assertCreated();

    $this->actingAs($other)->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertOk();
});

/* ------------------------------------------------------------- reporting */

it('takes a refund off revenue on the day it happened, never the day of the sale', function (): void {
    $saleDay = CarbonImmutable::now()->startOfDay();
    $order = paidOrder($this->learner, [$this->poetryProduct, $this->proseProduct], $this->currency);

    $this->travel(1)->days();
    refund($this->admin, $order, ['amount_minor' => 1_001, 'method' => 'external'])->assertCreated();

    app(BuildDailyRollups::class)->handle($saleDay);
    app(BuildDailyRollups::class)->handle($saleDay->addDay());

    $sale = DailyPlatformStat::query()->where('date', $saleDay->toDateString())->sole();
    $refundDay = DailyPlatformStat::query()->where('date', $saleDay->addDay()->toDateString())->sole();
    $coursesOnRefundDay = (int) DailyCourseStat::query()->where('date', $saleDay->addDay()->toDateString())->sum('revenue_minor');

    expect($sale->revenue_minor)->toBe(7_800)
        ->and($refundDay->revenue_minor)->toBe(-1_001)
        ->and($coursesOnRefundDay + $refundDay->download_revenue_minor)->toBe($refundDay->revenue_minor);
});

/* ------------------------------------------------------------- who may */

it('refuses anybody without order.refund — including the learner who paid', function (string $who): void {
    $order = paidOrder($this->learner, [$this->poetryProduct], $this->currency);
    $actor = $who === 'owner' ? $this->learner : userWithRole(RoleKey::from($who));

    refund($actor, $order, ['amount_minor' => 100])->assertForbidden();

    expect(Refund::count())->toBe(0);
})->with(['owner', 'instructor', 'staff']);

it('validates a refund', function (array $body): void {
    $order = paidOrder($this->learner, [$this->poetryProduct], $this->currency);

    expect(refund($this->admin, $order, $body)->assertStatus(422))->toBeApiError('validation_failed');
})->with([
    'no amount' => [['amount_minor' => null]],
    'nothing' => [['amount_minor' => 0]],
    'an unknown method' => [['amount_minor' => 100, 'method' => 'cheque']],
]);
