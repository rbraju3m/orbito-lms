<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\CouponRedemption;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Commerce\Models\Product;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Testing\TestResponse;

/*
 * A coupon, from the code a learner types to the order it discounts.
 *
 * The rules are asked twice — by the basket, for a preview and a reason, and
 * by checkout, under a lock, to enforce — and they are the same class, so
 * several tests here check that the two agree.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->currency = strtoupper((string) config('orbito.currency.base'));

    PaymentGatewayAccount::create([
        'gateway' => Gateway::Fake,
        'credentials' => ['key' => 'sk_test_coupons'],
        'webhook_secret' => 'whsec_coupons',
        'is_active' => true,
    ]);

    $courseA = courseWithCurriculum(Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]), [1]);
    $courseB = courseWithCurriculum(Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]), [1]);

    $this->productA = Product::factory()->pricedAt(4_900, $this->currency)
        ->create(['purchasable_id' => $courseA->id, 'title' => 'Poetry']);
    $this->productB = Product::factory()->pricedAt(2_900, $this->currency)
        ->create(['purchasable_id' => $courseB->id, 'title' => 'Prose']);

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
});

function couponBasket(User $learner, Product ...$products): void
{
    foreach ($products as $product) {
        test()->actingAs($learner)->postJson('/api/v1/cart/items', ['product_id' => $product->uuid])->assertCreated();
    }
}

function applyCode(User $learner, string $code): TestResponse
{
    return test()->actingAs($learner)->postJson('/api/v1/cart/coupon', ['code' => $code]);
}

function checkOut(User $learner): TestResponse
{
    return test()->actingAs($learner)->postJson('/api/v1/checkout');
}

/* ------------------------------------------------------------- applying */

it('applies a code however it is typed, and previews the discount', function (): void {
    Coupon::factory()->percent(20)->create(['code' => 'LAUNCH20']);
    couponBasket($this->student, $this->productA, $this->productB);

    $cart = applyCode($this->student, '  launch20 ')->assertOk()->json('data');

    expect($cart['coupon'])->toMatchArray(['code' => 'LAUNCH20', 'applies' => true, 'reason' => null])
        ->and($cart['estimated_subtotal_minor'])->toBe(7_800)
        ->and($cart['estimated_discount_minor'])->toBe(1_560)
        ->and($cart['estimated_total_minor'])->toBe(6_240)
        ->and(array_sum(array_column($cart['items'], 'discount_minor')))->toBe(1_560);
});

it('refuses a code that does not exist', function (): void {
    couponBasket($this->student, $this->productA);

    $response = applyCode($this->student, 'NOPE')->assertStatus(422);

    expect($response)->toBeApiError('coupon_rejected')
        ->and($response->json('error.meta.reason'))->toBe('not_found');
});

it('says why a coupon does not apply', function (array $attributes, string $reason): void {
    Coupon::factory()->create(['code' => 'WHY', ...$attributes]);
    couponBasket($this->student, $this->productA);

    expect(applyCode($this->student, 'WHY')->assertStatus(422)->json('error.meta.reason'))->toBe($reason);
})->with([
    'switched off' => [['is_active' => false], 'inactive'],
    'not started' => [['starts_at' => now()->addDay()], 'not_started'],
    'expired' => [['ends_at' => now()->subMinute()], 'expired'],
    'another currency' => [['currency' => 'EUR'], 'wrong_currency'],
    'below the minimum' => [['currency' => 'BDT', 'min_subtotal_minor' => 10_000], 'below_minimum'],
]);

it('refuses a scoped coupon when nothing in the basket is in scope', function (): void {
    $coupon = Coupon::factory()->create(['code' => 'PROSE', 'applies_to_all' => false]);
    $coupon->products()->attach($this->productB->id);
    couponBasket($this->student, $this->productA);

    expect(applyCode($this->student, 'PROSE')->assertStatus(422)->json('error.meta.reason'))->toBe('nothing_eligible');
});

it('takes the coupon off again', function (): void {
    Coupon::factory()->percent(20)->create(['code' => 'LAUNCH20']);
    couponBasket($this->student, $this->productA);
    applyCode($this->student, 'LAUNCH20')->assertOk();

    $this->actingAs($this->student)->deleteJson('/api/v1/cart/coupon')
        ->assertOk()
        ->assertJsonPath('data.coupon', null)
        ->assertJsonPath('data.estimated_total_minor', 4_900);
});

/* ------------------------------------------------------------ checkout */

it('charges the discounted total and splits the discount across the lines', function (): void {
    Coupon::factory()->percent(20)->create(['code' => 'LAUNCH20']);
    couponBasket($this->student, $this->productA, $this->productB);
    applyCode($this->student, 'LAUNCH20')->assertOk();

    $order = checkOut($this->student)->assertCreated()->json('data');

    expect($order)->toMatchArray([
        'coupon_code' => 'LAUNCH20',
        'subtotal_minor' => 7_800,
        'discount_minor' => 1_560,
        'total_minor' => 6_240,
        'status' => 'pending',
    ]);

    // Every revenue figure sums LINES: they have to add up to the order.
    $lines = collect($order['items']);
    expect($lines->sum('discount_minor'))->toBe(1_560)
        ->and($lines->sum('total_minor'))->toBe(6_240);
    $lines->each(fn (array $line) => expect($line['total_minor'])->toBe($line['unit_amount_minor'] - $line['discount_minor']));

    expect(CouponRedemption::sole()->discount_minor)->toBe(1_560);
});

it('discounts only the products a scoped coupon names', function (): void {
    $coupon = Coupon::factory()->percent(50)->create(['code' => 'HALFPOETRY', 'applies_to_all' => false]);
    $coupon->products()->attach($this->productA->id);
    couponBasket($this->student, $this->productA, $this->productB);
    applyCode($this->student, 'HALFPOETRY')->assertOk();

    checkOut($this->student)->assertCreated();
    $order = Order::with('items')->sole();

    expect($order->discount_minor)->toBe(2_450)
        ->and($order->items->firstWhere('product_id', $this->productB->id)->discount_minor)->toBe(0);
});

/* The preview and the order are one set of rules: they must agree. */
it('charges exactly what the basket previewed', function (): void {
    Coupon::factory()->fixed(1_001, $this->currency)->create(['code' => 'ODD']);
    couponBasket($this->student, $this->productA, $this->productB);

    $preview = applyCode($this->student, 'ODD')->assertOk()->json('data');
    $order = checkOut($this->student)->assertCreated()->json('data');

    expect($order['total_minor'])->toBe($preview['estimated_total_minor'])
        ->and(collect($order['items'])->pluck('discount_minor')->all())
        ->toBe(collect($preview['items'])->pluck('discount_minor')->all());
});

/* ------------------------------------------------------- a free order */

it('completes an order a coupon made free, with no gateway', function (array $coupon): void {
    Coupon::factory()->state($coupon)->create(['code' => 'FREE']);
    couponBasket($this->student, $this->productA, $this->productB);
    applyCode($this->student, 'FREE')->assertOk()->assertJsonPath('data.estimated_total_minor', 0);

    $order = checkOut($this->student)->assertCreated()->json('data');

    expect($order['status'])->toBe('paid')
        ->and($order['total_minor'])->toBe(0)
        ->and($order['grants_access'])->toBeTrue()
        ->and(Payment::count())->toBe(0)
        // Delivered by the same grant a real payment uses.
        ->and(Enrollment::where('user_id', $this->student->id)->count())->toBe(2);
})->with([
    'a hundred percent' => [['discount_type' => 'percent', 'percent_off' => 100]],
    'a fixed amount bigger than the basket' => [['discount_type' => 'fixed', 'amount_off_minor' => 1_000_000, 'currency' => 'BDT']],
]);

it('still sends a partly discounted order to the gateway', function (): void {
    Coupon::factory()->percent(20)->create(['code' => 'LAUNCH20']);
    couponBasket($this->student, $this->productA);
    applyCode($this->student, 'LAUNCH20')->assertOk();

    checkOut($this->student)->assertCreated()->assertJsonPath('data.status', 'pending');

    expect(Enrollment::where('user_id', $this->student->id)->count())->toBe(0);
});

/* -------------------------------------------------------------- limits */

it('refuses the use after the last one', function (): void {
    Coupon::factory()->create(['code' => 'ONLYONE', 'max_redemptions' => 1]);
    $other = User::factory()->withRole(RoleKey::Student)->create();

    couponBasket($this->student, $this->productA);
    applyCode($this->student, 'ONLYONE')->assertOk();
    checkOut($this->student)->assertCreated();

    couponBasket($other, $this->productA);

    expect(applyCode($other, 'ONLYONE')->assertStatus(422)->json('error.meta.reason'))->toBe('exhausted');
});

/* Read from the clock — nothing sweeps an abandoned checkout. */
it('gives a use back when an unpaid checkout is abandoned', function (): void {
    Coupon::factory()->create(['code' => 'ONLYONE', 'max_redemptions' => 1]);
    $other = User::factory()->withRole(RoleKey::Student)->create();

    couponBasket($this->student, $this->productA);
    applyCode($this->student, 'ONLYONE')->assertOk();
    checkOut($this->student)->assertCreated();

    $this->travel((int) config('orbito.coupons.reservation_minutes') + 1)->minutes();

    couponBasket($other, $this->productA);
    applyCode($other, 'ONLYONE')->assertOk();
});

it('counts a paid use for ever', function (): void {
    Coupon::factory()->create(['code' => 'ONLYONE', 'max_redemptions' => 1]);
    $other = User::factory()->withRole(RoleKey::Student)->create();

    couponBasket($this->student, $this->productA);
    applyCode($this->student, 'ONLYONE')->assertOk();
    checkOut($this->student)->assertCreated();
    Order::query()->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);

    $this->travel(30)->days();

    couponBasket($other, $this->productA);
    expect(applyCode($other, 'ONLYONE')->assertStatus(422)->json('error.meta.reason'))->toBe('exhausted');
});

it('holds one person to their own limit', function (): void {
    Coupon::factory()->create(['code' => 'ONCEEACH', 'max_redemptions_per_user' => 1]);

    couponBasket($this->student, $this->productA);
    applyCode($this->student, 'ONCEEACH')->assertOk();
    checkOut($this->student)->assertCreated();

    couponBasket($this->student, $this->productB);
    expect(applyCode($this->student, 'ONCEEACH')->assertStatus(422)->json('error.meta.reason'))->toBe('already_used');

    // Somebody else still may.
    $other = User::factory()->withRole(RoleKey::Student)->create();
    couponBasket($other, $this->productB);
    applyCode($other, 'ONCEEACH')->assertOk();
});

/* ------------------------------------------------ when it stops applying */

it('blocks checkout, with the reason, when an applied coupon stops applying', function (): void {
    $coupon = Coupon::factory()->percent(20)->create(['code' => 'LAUNCH20']);
    couponBasket($this->student, $this->productA);
    applyCode($this->student, 'LAUNCH20')->assertOk();

    $coupon->update(['is_active' => false]);

    $cart = $this->actingAs($this->student)->getJson('/api/v1/cart')->assertOk()->json('data');

    expect($cart['coupon'])->toMatchArray(['applies' => false, 'reason' => 'inactive'])
        ->and($cart['estimated_discount_minor'])->toBe(0)
        ->and($cart['is_checkoutable'])->toBeFalse();

    // Checkout refuses rather than charge a price the learner was not shown.
    expect(checkOut($this->student)->assertStatus(422))->toBeApiError('coupon_rejected');
    expect(Order::count())->toBe(0);
});
