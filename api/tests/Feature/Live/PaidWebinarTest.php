<?php

declare(strict_types=1);

use App\Domain\Commerce\Actions\CapturePayment;
use App\Domain\Commerce\Actions\InitiatePayment;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Data\WebhookEvent;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\ProductStatus;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Commerce\Models\Product;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use Carbon\CarbonImmutable;

/*
 * A webinar somebody has to buy a place at.
 *
 * `is_paid` and `product_id` sat on the model from P15 and nothing ever wrote
 * either: every webinar the product could make was free. What this file
 * proves is the whole of the difference — that a ticket cannot be published
 * without a price, cannot be registered for free, is delivered by the same
 * `GrantOrderAccess` every other purchase goes through, and comes back off
 * the list when the money is refunded.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->currency = strtoupper((string) config('orbito.currency.base'));
    $this->admin = userWithRole(RoleKey::Admin);
    $this->learner = User::factory()->withRole(RoleKey::Student)->create();

    PaymentGatewayAccount::create([
        'gateway' => Gateway::Fake,
        'credentials' => ['key' => 'sk_test_webinars'],
        'webhook_secret' => 'whsec_webinars',
        'is_active' => true,
    ]);
});

/** Buys one product outright: placed, paid through the test gateway, granted. */
function buyProduct(User $buyer, Product $product, string $currency): Order
{
    $cart = Cart::create(['user_id' => $buyer->id, 'currency' => $currency]);
    $cart->items()->create(['product_id' => $product->id]);

    $order = app(PlaceOrder::class)->handle($buyer, $cart->load('items.product.prices'));
    app(InitiatePayment::class)->handle($order, Gateway::Fake);

    $payment = $order->payments()->firstOrFail();

    app(CapturePayment::class)->handle($payment, new WebhookEvent(
        id: 'evt_webinar_'.$order->id,
        type: 'payment.captured',
        externalPaymentId: $payment->external_id,
        amountMinor: $payment->amount_minor,
        currency: $payment->currency,
        payload: [],
    ));

    return $order->refresh();
}

/* ------------------------------------------------- the application's own way */

/*
 * Everything else in this file starts from `Webinar::factory()->paid()`. This
 * one does not, because a factory that mints what the application is supposed
 * to mint hides a missing endpoint indefinitely — which is exactly how
 * webinars came to have a model, a registration flow and a screen with no way
 * to create one (§ Patterns established in Phase 16).
 */
it('sells a place at a webinar the product itself built, end to end', function (): void {
    $created = $this->actingAs($this->admin)
        ->postJson('/api/v1/webinars', [
            'title' => 'Masterclass',
            'description' => 'Two hours on structure.',
            'is_paid' => true,
            'provider' => 'manual',
            'join_url' => 'https://meet.example.test/masterclass',
            'starts_at' => now()->addWeek()->toIso8601String(),
            'ends_at' => now()->addWeek()->addHours(2)->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_paid', true)
        // Priced at nothing yet, so not publishable — and it says why.
        ->assertJsonPath('data.is_publishable', false)
        ->assertJsonPath('data.publish_blockers.0.code', 'webinar_needs_price');

    $id = $created->json('data.id');

    // The product exists from creation, dormant, so there is something to
    // hang a price on while the event is still a draft.
    $webinar = Webinar::query()->where('uuid', $id)->sole();
    expect($webinar->product)->not->toBeNull()
        ->and($webinar->product->status)->toBe(ProductStatus::Inactive);

    $this->actingAs($this->admin)
        ->putJson("/api/v1/webinars/{$id}/price", [
            'currency' => $this->currency,
            'amount_minor' => 3_500,
        ])
        ->assertOk()
        ->assertJsonPath('data.amount_minor', 3500);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/webinars/{$id}/status", ['status' => 'published'])
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.price.amount_minor', 3500);

    // Publishing is what opens the sale.
    expect($webinar->refresh()->product->status)->toBe(ProductStatus::Active);

    $order = buyProduct($this->learner, $webinar->product, $this->currency);

    $this->actingAs($this->learner)
        ->getJson("/api/v1/webinars/{$id}")
        ->assertOk()
        ->assertJsonPath('data.is_registered', true);

    $registration = WebinarRegistration::query()->where('webinar_id', $webinar->id)->sole();

    expect($registration->email)->toBe($this->learner->email)
        // Keyed on the order, which is what lets a refund take back exactly
        // this place and nothing given away.
        ->and($registration->order_id)->toBe($order->id)
        ->and($order->total_minor)->toBe(3_500);
});

/* --------------------------------------------------------------- publishing */

it('refuses to publish a paid webinar with no price, and says so on the row', function (): void {
    $webinar = Webinar::factory()->withSession()->create(['is_paid' => true]);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/status", ['status' => 'published'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'webinar_needs_price')
        ->assertJsonPath('error.meta.blockers.0.field', 'price');

    // The rule the refusal came from is the rule the row is drawn with, so
    // the button is disabled rather than offered and refused.
    $this->actingAs($this->admin)
        ->getJson("/api/v1/webinars/{$webinar->uuid}")
        ->assertOk()
        ->assertJsonPath('data.is_publishable', false);
});

it('reports the missing session first when both are missing', function (): void {
    // An event with no time cannot be priced into existence either, so the
    // more fundamental complaint is the one the rejection is reported as.
    $webinar = Webinar::factory()->create(['is_paid' => true]);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/status", ['status' => 'published'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'webinar_needs_session')
        ->assertJsonPath('error.meta.blockers.1.code', 'webinar_needs_price');
});

/* -------------------------------------------------------------- registering */

it('423s a free registration at a paid webinar, and names the product to buy', function (): void {
    $webinar = Webinar::factory()->withSession()->published()->paid(2_500)->create();

    $response = $this->actingAs($this->learner)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/register")
        // 423, not 403: nothing was done wrong and there is a way in.
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'webinar_requires_purchase');

    expect($response->json('error.meta.product_id'))
        ->toBe($webinar->refresh()->product->uuid);

    expect(WebinarRegistration::query()->count())->toBe(0);
});

it('still registers free at a free webinar', function (): void {
    $webinar = Webinar::factory()->withSession()->published()->create();

    $this->actingAs($this->learner)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertCreated()
        ->assertJsonPath('data.is_registered', true);
});

it('409s a paid webinar whose price was taken away after it was published', function (): void {
    // Not reachable through the UI — the publish rule refuses it — but a
    // learner holding the link deserves better than a 500.
    $webinar = Webinar::factory()->withSession()->published()->create(['is_paid' => true]);

    $this->actingAs($this->learner)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'webinar_not_on_sale');
});

/* ------------------------------------------------------------------ pricing */

it('refuses to price a free webinar and names the field to change', function (): void {
    $webinar = Webinar::factory()->withSession()->create();

    $this->actingAs($this->admin)
        ->putJson("/api/v1/webinars/{$webinar->uuid}/price", [
            'currency' => $this->currency,
            'amount_minor' => 1_000,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'pricing_rejected')
        ->assertJsonPath('error.details.0.field', 'pricing_model');
});

it('refuses a price from somebody who cannot manage webinars', function (): void {
    $webinar = Webinar::factory()->withSession()->paid()->create();
    $instructor = User::factory()->instructor()->create();

    $this->actingAs($instructor)
        ->putJson("/api/v1/webinars/{$webinar->uuid}/price", [
            'currency' => $this->currency,
            'amount_minor' => 1_000,
        ])
        ->assertForbidden();
});

it('retires the product when a webinar goes free, and keeps the price for when it comes back', function (): void {
    $webinar = Webinar::factory()->withSession()->published()->paid(4_000)->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/webinars/{$webinar->uuid}", ['is_paid' => false])
        ->assertOk()
        ->assertJsonPath('data.is_paid', false)
        // Free again, so there is nothing to show a price for.
        ->assertJsonPath('data.price', null);

    expect($webinar->refresh()->product->status)->toBe(ProductStatus::Inactive);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/webinars/{$webinar->uuid}", ['is_paid' => true])
        ->assertOk()
        // The figure somebody typed survived the round trip: the product was
        // retired, never deleted.
        ->assertJsonPath('data.price.amount_minor', 4000);
});

it('stops selling a webinar that is called off or deleted', function (): void {
    $cancelled = Webinar::factory()->withSession()->published()->paid()->create();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/webinars/{$cancelled->uuid}/status", ['status' => 'cancelled'])
        ->assertOk();

    expect($cancelled->refresh()->product->status)->toBe(ProductStatus::Inactive);

    $deleted = Webinar::factory()->withSession()->published()->paid()->create();
    $productId = $deleted->product->id;

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/webinars/{$deleted->uuid}")
        ->assertNoContent();

    // Retired, never deleted: an order line already points at it.
    expect(Product::query()->find($productId)->status)->toBe(ProductStatus::Inactive);
});

/* ------------------------------------------------------------------- buying */

it('refuses a basket holding a place the buyer already has', function (): void {
    $webinar = Webinar::factory()->withSession()->published()->paid()->create();

    buyProduct($this->learner, $webinar->product, $this->currency);

    $this->actingAs($this->learner)
        ->postJson('/api/v1/cart/items', ['product_id' => $webinar->refresh()->product->uuid])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'checkout_rejected');
});

it('refuses a basket holding an event that is full or already over', function (): void {
    $full = Webinar::factory()->withSession()->published()->paid()->withCapacity(1)->create();
    WebinarRegistration::create([
        'webinar_id' => $full->id,
        'email' => 'someone@example.test',
        'status' => WebinarRegistration::STATUS_REGISTERED,
        'registered_at' => now(),
    ]);

    $this->actingAs($this->learner)
        ->postJson('/api/v1/cart/items', ['product_id' => $full->product->uuid])
        ->assertStatus(409);

    $over = Webinar::factory()->published()->paid()->create();
    $over->session()->associate(LiveSession::factory()
        ->startingAt(CarbonImmutable::now()->subDays(2))
        ->create(['course_id' => null, 'cohort_id' => null]))->save();

    $this->actingAs($this->learner)
        ->postJson('/api/v1/cart/items', ['product_id' => $over->product->uuid])
        ->assertStatus(409);
});

it('registers a payment that lands on a full room rather than keeping the money', function (): void {
    // The capacity check happens at the basket and at checkout. It is NOT
    // repeated when the money lands: a room with one extra person in it is a
    // smaller failure than a learner who has paid and holds nothing.
    $webinar = Webinar::factory()->withSession()->published()->paid()->withCapacity(1)->create();

    $cart = Cart::create(['user_id' => $this->learner->id, 'currency' => $this->currency]);
    $cart->items()->create(['product_id' => $webinar->product->id]);
    $order = app(PlaceOrder::class)->handle($this->learner, $cart->load('items.product.prices'));

    // Somebody else takes the last place between checkout and capture.
    WebinarRegistration::create([
        'webinar_id' => $webinar->id,
        'email' => 'first@example.test',
        'status' => WebinarRegistration::STATUS_REGISTERED,
        'registered_at' => now(),
    ]);

    app(InitiatePayment::class)->handle($order, Gateway::Fake);
    $payment = $order->payments()->firstOrFail();
    app(CapturePayment::class)->handle($payment, new WebhookEvent(
        id: 'evt_full',
        type: 'payment.captured',
        externalPaymentId: $payment->external_id,
        amountMinor: $payment->amount_minor,
        currency: $payment->currency,
        payload: [],
    ));

    expect(WebinarRegistration::query()
        ->where('webinar_id', $webinar->id)
        ->where('email', $this->learner->email)
        ->where('status', WebinarRegistration::STATUS_REGISTERED)
        ->exists())->toBeTrue();
});

it('refuses to let somebody give up a place they bought', function (): void {
    $webinar = Webinar::factory()->withSession()->published()->paid()->create();

    buyProduct($this->learner, $webinar->product, $this->currency);

    // Re-registering at a paid event 423s, so allowing the cancel would lock
    // them out of something they paid for. Giving it up is a refund.
    $this->actingAs($this->learner)
        ->deleteJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'webinar_place_purchased');

    expect(WebinarRegistration::query()->where('webinar_id', $webinar->id)->sole()->status)
        ->toBe(WebinarRegistration::STATUS_REGISTERED);
});

it('still lets somebody give up a free place', function (): void {
    $webinar = Webinar::factory()->withSession()->published()->create();

    $this->actingAs($this->learner)
        ->postJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertCreated();

    $this->actingAs($this->learner)
        ->deleteJson("/api/v1/webinars/{$webinar->uuid}/register")
        ->assertOk()
        ->assertJsonPath('data.is_registered', false);
});

/* ------------------------------------------------------------------ refunds */

it('gives the place back when the money goes back', function (): void {
    $webinar = Webinar::factory()->withSession()->published()->paid(3_000)->withCapacity(1)->create();

    $order = buyProduct($this->learner, $webinar->product, $this->currency);

    expect($webinar->refresh()->placesRemaining())->toBe(0);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/orders/{$order->uuid}/refunds", [
            'amount_minor' => 3_000,
            'method' => 'gateway',
            'reason' => 'They asked.',
        ])
        ->assertCreated();

    $registration = WebinarRegistration::query()->where('webinar_id', $webinar->id)->sole();

    // Cancelled, not deleted: the record that somebody was coming survives,
    // and the place goes back into the room.
    expect($registration->status)->toBe(WebinarRegistration::STATUS_CANCELLED)
        ->and($webinar->refresh()->placesRemaining())->toBe(1);
});

it('leaves a place the academy gave away alone when an order is refunded', function (): void {
    $webinar = Webinar::factory()->withSession()->published()->paid()->create();
    $guest = User::factory()->withRole(RoleKey::Student)->create();

    // A place with no order behind it — the shape a free registration leaves,
    // and the one `RevokeOrderAccess` must never touch.
    WebinarRegistration::create([
        'webinar_id' => $webinar->id,
        'user_id' => $guest->id,
        'email' => $guest->email,
        'status' => WebinarRegistration::STATUS_REGISTERED,
        'registered_at' => now(),
    ]);

    $order = buyProduct($this->learner, $webinar->product, $this->currency);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/orders/{$order->uuid}/refunds", [
            'amount_minor' => $order->total_minor,
            'method' => 'gateway',
        ])
        ->assertCreated();

    expect(WebinarRegistration::query()->where('email', $guest->email)->sole()->status)
        ->toBe(WebinarRegistration::STATUS_REGISTERED);
});
