<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Actions\HandleWebhook;
use App\Domain\Commerce\Actions\InitiatePayment;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Actions\RefundOrder;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Enums\RefundMethod;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\PaymentEvent;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/*
 * Stripe Checkout: the learner pays on Stripe's hosted page, and only a
 * verified webhook grants (ADR-05). What matters most is what does NOT grant:
 * a completed session that is not paid yet, a session paid for less than the
 * order, the learner arriving back on the success page.
 *
 * ⚠ Against a faked Stripe API and bodies in Stripe's documented shape; no
 * request here has reached Stripe.
 */

const CHECKOUT_SECRET = 'whsec_stripe_checkout';

beforeEach(function (): void {
    seedRegistry();

    PaymentGatewayAccount::create([
        'gateway' => Gateway::Stripe,
        'credentials' => ['key' => 'sk_test_checkout'],
        'webhook_secret' => CHECKOUT_SECRET,
        'is_active' => true,
        'is_test_mode' => true,
    ]);

    $this->admin = userWithRole(RoleKey::Admin);
    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    $this->course = courseWithCurriculum(
        Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]),
        [1],
    );

    $product = Product::factory()->pricedAt(4_900)
        ->create(['purchasable_id' => $this->course->id, 'title' => 'Poetry']);

    $cart = Cart::create(['user_id' => $this->student->id, 'currency' => 'USD']);
    $cart->items()->create(['product_id' => $product->id]);
    $this->order = app(PlaceOrder::class)->handle($this->student, $cart->load('items.product.prices'));

    Http::preventStrayRequests();
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_session',
        ]),
        'api.stripe.com/v1/refunds' => Http::response(['id' => 're_after_checkout', 'status' => 'succeeded']),
    ]);
});

/**
 * An event in Stripe's shape, signed with Stripe's scheme, through the real
 * handler.
 *
 * @param  array<string, mixed>  $object
 */
function stripeDelivers(string $eventId, string $type, array $object): PaymentEvent
{
    $body = json_encode(['id' => $eventId, 'type' => $type, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
    $timestamp = time();

    return app(HandleWebhook::class)->handle(Request::create('/webhooks/payments/stripe', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", CHECKOUT_SECRET),
    ], $body), Gateway::Stripe);
}

/**
 * The session the fake handed off, as Stripe reports it once paid.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checkoutSession(array $overrides = []): array
{
    return $overrides + [
        'id' => 'cs_test_session',
        'object' => 'checkout.session',
        'amount_total' => 4_900,
        'currency' => 'usd',
        'payment_status' => 'paid',
        'payment_intent' => 'pi_from_checkout',
        'status' => 'complete',
    ];
}

function studentEnrolled(User $student): bool
{
    return Enrollment::query()->where('user_id', $student->id)->exists();
}

/* ------------------------------------------------------------ the handoff */

it('sends the learner to Stripe\'s hosted page, priced by us', function (): void {
    $this->freezeTime();

    $handoff = app(InitiatePayment::class)->handle($this->order, Gateway::Stripe);

    expect($handoff->redirectUrl)->toBe('https://checkout.stripe.com/c/pay/cs_test_session')
        ->and($handoff->externalId)->toBe('cs_test_session')
        ->and(Payment::sole()->external_id)->toBe('cs_test_session');

    Http::assertSent(function (HttpRequest $request): bool {
        $data = $request->data();
        $uuid = $this->order->uuid;

        return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $data['mode'] === 'payment'
            // One line at OUR total — coupons and all — so Stripe reprices nothing.
            && (int) $data['line_items[0][price_data][unit_amount]'] === 4_900
            && $data['line_items[0][price_data][currency]'] === 'usd'
            && (int) $data['line_items[0][quantity]'] === 1
            && $data['client_reference_id'] === $uuid
            && $data['metadata[order_uuid]'] === $uuid
            && str_ends_with((string) $data['success_url'], "/orders/{$uuid}?paid=1")
            && str_ends_with((string) $data['cancel_url'], "/orders/{$uuid}")
            // Payable only while a coupon use on the order is still held.
            && (int) $data['expires_at'] === now()->addMinutes(60)->getTimestamp();
    });
});

/* ---------------------------------------------------------------- granting */

it('grants when the session is paid, and keeps the PaymentIntent for refunds', function (): void {
    app(InitiatePayment::class)->handle($this->order, Gateway::Stripe);

    $event = stripeDelivers('evt_completed', 'checkout.session.completed', checkoutSession());
    $payment = Payment::sole();

    expect($payment->status)->toBe(PaymentStatus::Captured)
        ->and($payment->provider_payment_id)->toBe('pi_from_checkout')
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(studentEnrolled($this->student))->toBeTrue()
        ->and($event->processed_at)->not->toBeNull();
});

/*
 * A bank debit completes the session before the money arrives. Granting on
 * `completed` alone would hand over the course for a payment that may fail.
 */
it('grants nothing for a completed session that is not paid yet — then grants when it is', function (): void {
    app(InitiatePayment::class)->handle($this->order, Gateway::Stripe);

    stripeDelivers('evt_completed_unpaid', 'checkout.session.completed', checkoutSession([
        'payment_status' => 'unpaid',
        'payment_intent' => 'pi_slow',
    ]));

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending)
        ->and(studentEnrolled($this->student))->toBeFalse();

    stripeDelivers('evt_async_paid', 'checkout.session.async_payment_succeeded', checkoutSession([
        'payment_intent' => 'pi_slow',
    ]));

    expect(Payment::sole()->status)->toBe(PaymentStatus::Captured)
        ->and(Payment::sole()->provider_payment_id)->toBe('pi_slow')
        ->and(studentEnrolled($this->student))->toBeTrue();
});

it('fails the payment when a slow payment fails or the session expires', function (string $type): void {
    app(InitiatePayment::class)->handle($this->order, Gateway::Stripe);

    stripeDelivers('evt_'.$type, $type, checkoutSession([
        'payment_status' => 'unpaid',
        'status' => $type === 'checkout.session.expired' ? 'expired' : 'complete',
    ]));

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed)
        ->and(studentEnrolled($this->student))->toBeFalse()
        // The order can still be paid for — a new session, a new payment row.
        ->and($this->order->refresh()->status->isPayable())->toBeTrue();
})->with(['checkout.session.async_payment_failed', 'checkout.session.expired']);

it('grants nothing for a session paid for less than the order', function (): void {
    app(InitiatePayment::class)->handle($this->order, Gateway::Stripe);

    stripeDelivers('evt_short', 'checkout.session.completed', checkoutSession(['amount_total' => 100]));

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed)
        ->and(Payment::sole()->failure_reason)->toBe('Captured amount is less than the order total.')
        ->and(studentEnrolled($this->student))->toBeFalse();
});

/* ----------------------------------------------------------------- refunds */

it('refunds through the PaymentIntent the session produced', function (): void {
    app(InitiatePayment::class)->handle($this->order, Gateway::Stripe);
    stripeDelivers('evt_paid', 'checkout.session.completed', checkoutSession());

    $refund = app(RefundOrder::class)->handle($this->admin, $this->order->refresh(), 4_900, RefundMethod::Gateway, null, true);

    expect($refund->status)->toBe(RefundStatus::Completed);

    // Not the `cs_…` session: Stripe refunds a PaymentIntent.
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/refunds'
        && $request->data()['payment_intent'] === 'pi_from_checkout');
});

it('finds a Checkout payment by its PaymentIntent when Stripe reports a refund', function (): void {
    app(InitiatePayment::class)->handle($this->order, Gateway::Stripe);
    stripeDelivers('evt_paid', 'checkout.session.completed', checkoutSession());

    stripeDelivers('evt_dashboard_refund', 'refund.updated', [
        'id' => 're_from_dashboard',
        'object' => 'refund',
        'amount' => 1_000,
        'currency' => 'usd',
        'payment_intent' => 'pi_from_checkout',
        'status' => 'succeeded',
    ]);

    expect(Refund::sole()->external_id)->toBe('re_from_dashboard')
        ->and(Refund::sole()->payment_id)->toBe(Payment::sole()->id)
        ->and($this->order->refresh()->status)->toBe(OrderStatus::PartiallyRefunded);
});
