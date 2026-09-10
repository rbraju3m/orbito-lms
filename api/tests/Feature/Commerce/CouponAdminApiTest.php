<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\CouponRedemption;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Identity\Enums\RoleKey;
use Illuminate\Testing\TestResponse;

/*
 * Managing coupons (`coupon.manage` — Admin and Super Admin). A coupon is a
 * decision about the academy's prices, so an instructor cannot discount even
 * their own course.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->admin = userWithRole(RoleKey::Admin);
    $this->product = Product::factory()->pricedAt(4_900, 'BDT')
        ->create(['purchasable_id' => Course::factory()->published(), 'title' => 'Poetry']);
});

function saveCoupon(array $overrides = [], ?Coupon $coupon = null): TestResponse
{
    $body = $overrides + [
        'code' => 'launch-20',
        'description' => 'Launch week',
        'discount_type' => 'percent',
        'percent_off' => 20,
        'applies_to_all' => true,
        'is_active' => true,
    ];

    return $coupon === null
        ? test()->actingAs(test()->admin)->postJson('/api/v1/admin/coupons', $body)
        : test()->actingAs(test()->admin)->putJson("/api/v1/admin/coupons/{$coupon->uuid}", $body);
}

it('creates a coupon, storing its code one way', function (): void {
    $body = saveCoupon()->assertCreated()->json('data');

    expect($body)->toMatchArray([
        'code' => 'LAUNCH-20',
        'discount_type' => 'percent',
        'percent_off' => 20,
        'amount_off_minor' => null,
        'state' => 'active',
        'times_used' => 0,
    ]);
});

it('scopes a coupon to chosen products', function (): void {
    $body = saveCoupon(['applies_to_all' => false, 'product_ids' => [$this->product->uuid]])
        ->assertCreated()->json('data');

    expect($body['applies_to_all'])->toBeFalse()
        ->and($body['products'])->toBe([['id' => $this->product->uuid, 'title' => 'Poetry', 'type' => 'course']]);
});

it('replaces a coupon, dropping the value its new type does not use', function (): void {
    $coupon = Coupon::factory()->percent(20)->create();

    $body = saveCoupon([
        'code' => $coupon->code,
        'discount_type' => 'fixed',
        'amount_off_minor' => 1_000,
        'currency' => 'bdt',
    ], $coupon)->assertOk()->json('data');

    expect($body)->toMatchArray([
        'discount_type' => 'fixed',
        'amount_off_minor' => 1_000,
        'percent_off' => null,
        'currency' => 'BDT',
    ]);
});

it('lists coupons with how often each was PAID for', function (): void {
    $coupon = Coupon::factory()->create(['max_redemptions' => 1]);
    $paid = Order::factory()->create(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    CouponRedemption::create([
        'coupon_id' => $coupon->id, 'order_id' => $paid->id, 'user_id' => $paid->user_id,
        'discount_minor' => 100, 'currency' => 'BDT',
    ]);
    // An unpaid one is not a sale.
    $unpaid = Order::factory()->create(['status' => OrderStatus::Pending, 'placed_at' => now()]);
    CouponRedemption::create([
        'coupon_id' => $coupon->id, 'order_id' => $unpaid->id, 'user_id' => $unpaid->user_id,
        'discount_minor' => 100, 'currency' => 'BDT',
    ]);

    $row = $this->actingAs($this->admin)->getJson('/api/v1/admin/coupons')->assertOk()->json('data.0');

    expect($row['times_used'])->toBe(1)
        ->and($row['state'])->toBe('used_up');
});

it('derives a coupon\'s state from its dates', function (array $attributes, string $state): void {
    $coupon = Coupon::factory()->create($attributes);

    $this->actingAs($this->admin)->getJson("/api/v1/admin/coupons/{$coupon->uuid}")
        ->assertOk()->assertJsonPath('data.state', $state);
})->with([
    'scheduled' => [['starts_at' => now()->addWeek()], 'scheduled'],
    'expired' => [['ends_at' => now()->subDay()], 'expired'],
    'switched off' => [['is_active' => false], 'off'],
]);

it('deletes a coupon nobody has used', function (): void {
    $coupon = Coupon::factory()->create();

    $this->actingAs($this->admin)->deleteJson("/api/v1/admin/coupons/{$coupon->uuid}")->assertNoContent();

    expect(Coupon::count())->toBe(0);
});

/* A used coupon is part of somebody's receipt. */
it('refuses to delete a coupon that is on an order', function (): void {
    $coupon = Coupon::factory()->create();
    $order = Order::factory()->create();
    CouponRedemption::create([
        'coupon_id' => $coupon->id, 'order_id' => $order->id, 'user_id' => $order->user_id,
        'discount_minor' => 100, 'currency' => 'BDT',
    ]);

    expect($this->actingAs($this->admin)->deleteJson("/api/v1/admin/coupons/{$coupon->uuid}")->assertStatus(409))
        ->toBeApiError('coupon_in_use');

    expect(Coupon::count())->toBe(1);
});

it('lists what a coupon can be scoped to — only what is for sale', function (): void {
    Product::factory()->inactive()->create(['title' => 'Retired']);

    $titles = $this->actingAs($this->admin)->getJson('/api/v1/admin/coupons/products')
        ->assertOk()->json('data.*.title');

    expect($titles)->toContain('Poetry')->not->toContain('Retired');
});

it('refuses anybody without coupon.manage', function (RoleKey $role): void {
    $actor = userWithRole($role);
    $coupon = Coupon::factory()->create();

    $this->actingAs($actor)->getJson('/api/v1/admin/coupons')->assertForbidden();
    $this->actingAs($actor)->postJson('/api/v1/admin/coupons', [
        'code' => 'MINE', 'discount_type' => 'percent', 'percent_off' => 90,
        'applies_to_all' => true, 'is_active' => true,
    ])->assertForbidden();
    $this->actingAs($actor)->deleteJson("/api/v1/admin/coupons/{$coupon->uuid}")->assertForbidden();

    expect(Coupon::count())->toBe(1);
})->with([RoleKey::Instructor, RoleKey::Student, RoleKey::Staff]);

it('validates a coupon', function (array $overrides): void {
    expect(saveCoupon($overrides)->assertStatus(422))->toBeApiError('validation_failed');
    expect(Coupon::count())->toBe(0);
})->with([
    'a percentage over 100' => [['percent_off' => 150]],
    'a fixed amount with no currency' => [['discount_type' => 'fixed', 'amount_off_minor' => 500]],
    'a minimum spend with no currency' => [['min_subtotal_minor' => 10_000]],
    'scoped to nothing' => [['applies_to_all' => false, 'product_ids' => []]],
    'scoped to a product that does not exist' => [['applies_to_all' => false, 'product_ids' => ['nope']]],
    'spaces in the code' => [['code' => 'two words']],
    'an end before its start' => [['starts_at' => '2026-10-10T00:00:00Z', 'ends_at' => '2026-10-01T00:00:00Z']],
]);

it('refuses a code already taken, whatever its case', function (): void {
    Coupon::factory()->create(['code' => 'LAUNCH-20']);

    expect(saveCoupon(['code' => 'launch-20'])->assertStatus(422))->toBeApiError('validation_failed');
});
