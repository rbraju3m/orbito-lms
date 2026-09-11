<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Actions\CapturePayment;
use App\Domain\Commerce\Actions\InitiatePayment;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Actions\RefundOrder;
use App\Domain\Commerce\Data\WebhookEvent;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\RefundMethod;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Product;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
 * Stripe, connected the way the Payments screen connects it.
 *
 * Every other commerce test builds its gateway account directly and talks to
 * FakeGateway, which reads no credential at all — so when the screen saved the
 * key as `key` and StripeGateway read `secret_key`, a Stripe account connected
 * through the product could never check anybody out, and the suite stayed
 * green. These start at the admin's PUT, not at a factory (§ Patterns
 * established in Phase 16: a dead wire is invisible to a suite that starts
 * downstream of it).
 */

const STRIPE_KEY = 'sk_test_saved_from_the_payments_screen';

beforeEach(function (): void {
    seedRegistry();

    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    // Exactly the body PaymentGatewaysRoute sends.
    $this->actingAs($admin)
        ->putJson('/api/v1/admin/payment-gateways/stripe', [
            'credentials' => ['key' => STRIPE_KEY],
            'webhook_secret' => 'whsec_saved_from_the_payments_screen',
            'is_active' => true,
        ])
        ->assertOk();

    $this->admin = $admin;
    $this->student = User::factory()->withRole(RoleKey::Student)->create();

    $course = courseWithCurriculum(
        Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]),
        [1],
    );

    $this->product = Product::factory()
        ->pricedAt(4900)
        ->create(['purchasable_id' => $course->id, 'title' => $course->title]);

    Http::preventStrayRequests();
});

/** Placed from a basket holding the priced course. */
function stripeOrder(User $student, Product $product): Order
{
    $cart = Cart::create(['user_id' => $student->id, 'currency' => 'USD']);
    $cart->items()->create(['product_id' => $product->id]);

    return app(PlaceOrder::class)->handle($student, $cart->load('items.product.prices'));
}

it('checks out through Stripe with the key the Payments screen saved', function (): void {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_from_stripe',
            'client_secret' => 'pi_from_stripe_secret_abc',
        ]),
    ]);

    $handoff = app(InitiatePayment::class)->handle(stripeOrder($this->student, $this->product), Gateway::Stripe);

    expect($handoff->externalId)->toBe('pi_from_stripe');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents'
        && $request->hasHeader('Authorization', 'Bearer '.STRIPE_KEY));
});

it('refunds through Stripe with the same key', function (): void {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_to_refund', 'client_secret' => 'x']),
        'api.stripe.com/v1/refunds' => Http::response(['id' => 're_from_stripe', 'status' => 'succeeded']),
    ]);

    $order = stripeOrder($this->student, $this->product);
    app(InitiatePayment::class)->handle($order, Gateway::Stripe);

    $payment = Payment::where('order_id', $order->id)->firstOrFail();
    app(CapturePayment::class)->handle($payment, new WebhookEvent(
        id: 'evt_stripe_capture',
        type: 'payment_intent.succeeded',
        externalPaymentId: 'pi_to_refund',
        amountMinor: $payment->amount_minor,
        currency: $payment->currency,
        payload: [],
    ));

    $refund = app(RefundOrder::class)->handle(
        $this->admin,
        $order->refresh(),
        4900,
        RefundMethod::Gateway,
        null,
        true,
    );

    expect($refund->status)->toBe(RefundStatus::Completed)
        ->and($refund->external_id)->toBe('re_from_stripe');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/refunds'
        && $request->hasHeader('Authorization', 'Bearer '.STRIPE_KEY));
});
