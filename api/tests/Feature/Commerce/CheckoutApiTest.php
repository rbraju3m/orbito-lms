<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Commerce\Models\Product;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Models\Subscription;

/*
 * The learner's buying surface over HTTP.
 *
 * The money path itself is proven in MoneyPathTest against the domain layer.
 * What is tested HERE is everything the HTTP layer adds and can get wrong:
 * who may reach a route, what a 402 does to it, whether an id in a URL is
 * checked for ownership, and whether the envelope is the documented one.
 */

/*
 * `AddToCart` fixes a basket's currency to the platform's base currency, and
 * multi-currency checkout is deferred (ROADMAP P10). So the fixture is priced
 * in whatever that base is rather than a hardcoded one — pricing it in some
 * other currency would make every product unbuyable, which is correct
 * behaviour and a useless fixture.
 */
beforeEach(function (): void {
    seedRegistry();

    $this->currency = strtoupper((string) config('orbito.currency.base'));

    PaymentGatewayAccount::create([
        'gateway' => Gateway::Fake,
        'credentials' => ['key' => 'sk_test_api'],
        'webhook_secret' => 'whsec_api_test',
        'is_active' => true,
    ]);

    $this->course = courseWithCurriculum(
        Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]),
        [1],
    );

    $this->product = Product::factory()
        ->pricedAt(4900, $this->currency)
        ->create(['purchasable_id' => $this->course->id, 'title' => 'Paid course']);

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
});

function addToCart(User $user, Product $product): void
{
    test()->actingAs($user)
        ->postJson('/api/v1/cart/items', ['product_id' => $product->uuid])
        ->assertCreated();
}

/* ------------------------------------------------------------------ the cart */

it('returns an empty cart rather than a 404 before anything is added', function (): void {
    $this->actingAs($this->student)
        ->getJson('/api/v1/cart')
        ->assertOk()
        ->assertJsonPath('data.item_count', 0)
        ->assertJsonPath('data.estimated_total_minor', 0)
        // Nothing to buy is not a checkoutable basket.
        ->assertJsonPath('data.is_checkoutable', false);
});

it('prices a cart line from the server, never from the request', function (): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/cart/items', [
            'product_id' => $this->product->uuid,
            // Ignored entirely. If any of this reached the total the next
            // assertions would fail.
            'amount_minor' => 1,
            'price' => 0,
            'currency' => 'XXX',
        ])
        ->assertCreated()
        ->assertJsonPath('data.item_count', 1)
        ->assertJsonPath('data.estimated_total_minor', 4900)
        ->assertJsonPath('data.items.0.amount_minor', 4900)
        ->assertJsonPath('data.is_checkoutable', true);
});

it('treats adding the same product twice as one line', function (): void {
    addToCart($this->student, $this->product);
    addToCart($this->student, $this->product);

    $this->actingAs($this->student)
        ->getJson('/api/v1/cart')
        ->assertOk()
        ->assertJsonPath('data.item_count', 1);
});

it('refuses a product the learner already owns', function (): void {
    Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);

    expect($this->actingAs($this->student)->postJson('/api/v1/cart/items', [
        'product_id' => $this->product->uuid,
    ])->assertStatus(409))->toBeApiError('checkout_rejected');
});

it('refuses a product that is not for sale', function (): void {
    $this->product->update(['status' => 'inactive']);

    expect($this->actingAs($this->student)->postJson('/api/v1/cart/items', [
        'product_id' => $this->product->uuid,
    ])->assertStatus(409))->toBeApiError('checkout_rejected');
});

it('rejects a product id that is not a uuid', function (): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/cart/items', ['product_id' => $this->product->id])
        ->assertStatus(422);
});

it('will not let one learner delete another learners cart line', function (): void {
    addToCart($this->student, $this->product);
    $line = Cart::where('user_id', $this->student->id)->firstOrFail()->items()->firstOrFail();

    $other = User::factory()->withRole(RoleKey::Student)->create();

    // 404, not 403 — the caller has no business learning the row exists.
    $this->actingAs($other)
        ->deleteJson('/api/v1/cart/items/'.$line->id)
        ->assertNotFound();

    expect($line->fresh())->not->toBeNull();
});

/* -------------------------------------------------------------- the checkout */

it('turns the cart into an order and empties the basket', function (): void {
    addToCart($this->student, $this->product);

    $response = $this->actingAs($this->student)
        ->postJson('/api/v1/checkout')
        ->assertCreated()
        ->assertJsonPath('data.status', OrderStatus::Pending->value)
        ->assertJsonPath('data.total_minor', 4900)
        ->assertJsonPath('data.items.0.title', 'Paid course');

    // Consumed, so a second checkout cannot create a second order for a
    // course they are about to own.
    $this->actingAs($this->student)->getJson('/api/v1/cart')
        ->assertOk()->assertJsonPath('data.item_count', 0);

    expect(Order::where('uuid', $response->json('data.id'))->exists())->toBeTrue();
});

it('refuses to check out an empty basket', function (): void {
    expect($this->actingAs($this->student)->postJson('/api/v1/checkout')->assertStatus(409))
        ->toBeApiError('checkout_rejected');
});

it('hands the order to a gateway without granting anything', function (): void {
    addToCart($this->student, $this->product);
    $orderId = $this->actingAs($this->student)->postJson('/api/v1/checkout')->json('data.id');

    $this->actingAs($this->student)
        ->postJson("/api/v1/orders/{$orderId}/pay", ['gateway' => 'fake'])
        ->assertCreated()
        ->assertJsonPath('data.order_status', OrderStatus::AwaitingPayment->value)
        ->assertJsonPath('data.gateway', 'fake');

    // The whole point of ADR-05: handing off is not paying.
    expect(Payment::where('status', PaymentStatus::Pending)->count())->toBe(1)
        ->and(Enrollment::where('user_id', $this->student->id)->exists())->toBeFalse();
});

it('offers no endpoint a client could call to confirm its own payment', function (): void {
    addToCart($this->student, $this->product);
    $orderId = $this->actingAs($this->student)->postJson('/api/v1/checkout')->json('data.id');

    // Every shape a client might reach for. None of them exist, and the
    // absence is the security property (ADR-05).
    foreach (['confirm', 'complete', 'success', 'capture'] as $verb) {
        $this->actingAs($this->student)
            ->postJson("/api/v1/orders/{$orderId}/{$verb}")
            ->assertNotFound();
    }

    expect(Enrollment::where('user_id', $this->student->id)->exists())->toBeFalse();
});

it('will not let one learner pay another learners order', function (): void {
    addToCart($this->student, $this->product);
    $orderId = $this->actingAs($this->student)->postJson('/api/v1/checkout')->json('data.id');

    $other = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($other)
        ->postJson("/api/v1/orders/{$orderId}/pay", ['gateway' => 'fake'])
        ->assertForbidden();
});

it('rejects an unknown gateway name with 422, not 503', function (): void {
    addToCart($this->student, $this->product);
    $orderId = $this->actingAs($this->student)->postJson('/api/v1/checkout')->json('data.id');

    // "Not a payment method" is a validation failure. "We do not offer that
    // one" is the 503 below, and the difference must not depend on config.
    $this->actingAs($this->student)
        ->postJson("/api/v1/orders/{$orderId}/pay", ['gateway' => 'not_a_gateway'])
        ->assertStatus(422);
});

it('answers 503 for a real gateway the academy has not connected', function (): void {
    PaymentGatewayAccount::query()->update(['is_active' => false]);

    addToCart($this->student, $this->product);
    $orderId = $this->actingAs($this->student)->postJson('/api/v1/checkout')->json('data.id');

    expect($this->actingAs($this->student)
        ->postJson("/api/v1/orders/{$orderId}/pay", ['gateway' => 'fake'])
        ->assertStatus(503))->toBeApiError('gateway_unavailable');
});

/* ----------------------------------------------------------------- the orders */

it('shows a learner only their own orders', function (): void {
    addToCart($this->student, $this->product);
    $this->actingAs($this->student)->postJson('/api/v1/checkout')->assertCreated();

    $other = User::factory()->withRole(RoleKey::Student)->create();
    $otherCourse = courseWithCurriculum(
        Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]),
        [1],
    );
    $otherProduct = Product::factory()
        ->pricedAt(1000, $this->currency)
        ->create(['purchasable_id' => $otherCourse->id]);
    addToCart($other, $otherProduct);
    $this->actingAs($other)->postJson('/api/v1/checkout')->assertCreated();

    $this->actingAs($this->student)->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('shows every order to staff who hold order.view.any', function (): void {
    addToCart($this->student, $this->product);
    $this->actingAs($this->student)->postJson('/api/v1/checkout')->assertCreated();

    $staff = User::factory()->withRole(RoleKey::Staff)->create();

    $this->actingAs($staff)->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('forbids reading an order belonging to somebody else', function (): void {
    addToCart($this->student, $this->product);
    $orderId = $this->actingAs($this->student)->postJson('/api/v1/checkout')->json('data.id');

    $other = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($other)->getJson("/api/v1/orders/{$orderId}")->assertForbidden();
});

it('lets staff read an order they did not place, but not pay it', function (): void {
    addToCart($this->student, $this->product);
    $orderId = $this->actingAs($this->student)->postJson('/api/v1/checkout')->json('data.id');

    $staff = User::factory()->withRole(RoleKey::Staff)->create();

    $this->actingAs($staff)->getJson("/api/v1/orders/{$orderId}")->assertOk();

    // Reading is oversight. Paying would be creating a payment nobody made.
    $this->actingAs($staff)
        ->postJson("/api/v1/orders/{$orderId}/pay", ['gateway' => 'fake'])
        ->assertForbidden();
});

/* ------------------------------------------------------------- the boundaries */

it('requires authentication for every learner commerce route', function (): void {
    $this->getJson('/api/v1/cart')->assertUnauthorized();
    $this->postJson('/api/v1/checkout')->assertUnauthorized();
    $this->getJson('/api/v1/orders')->assertUnauthorized();
});

it('answers 402 for a write but still serves reads when the academy has lapsed', function (): void {
    addToCart($this->student, $this->product);

    Subscription::query()->update([
        'status' => SubscriptionStatus::Canceled,
        'current_period_ends_at' => now()->subMonths(2),
        'grace_days' => 0,
    ]);

    // Selling stops.
    expect($this->actingAs($this->student)->postJson('/api/v1/checkout')->assertStatus(402))
        ->toBeApiError('subscription_lapsed');

    // Reading never does.
    $this->actingAs($this->student)->getJson('/api/v1/orders')->assertOk();
    $this->actingAs($this->student)->getJson('/api/v1/cart')->assertOk();
});
