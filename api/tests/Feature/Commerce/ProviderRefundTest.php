<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Actions\CapturePayment;
use App\Domain\Commerce\Actions\ClaimRefund;
use App\Domain\Commerce\Actions\HandleWebhook;
use App\Domain\Commerce\Actions\InitiatePayment;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Actions\RefundOrder;
use App\Domain\Commerce\Data\WebhookEvent;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\RefundMethod;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Events\RefundIssued;
use App\Domain\Commerce\Exceptions\RefundRejected;
use App\Domain\Commerce\Exceptions\WebhookRejected;
use App\Domain\Commerce\Gateways\StripeGateway;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\PaymentEvent;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/*
 * Refunds the PROVIDER reports — ones asked for here and settled later, and
 * ones made in the provider's own dashboard.
 *
 * What matters most is what a report must NOT do: count one refund twice
 * because several events describe it, read our own refund as a stranger's
 * because its provider id was not stored yet, or quietly rewrite a refund the
 * provider now contradicts. A report that cannot be settled is left
 * unprocessed, for a person.
 */

const PROVIDER_REFUND_SECRET = 'whsec_provider_refunds';

beforeEach(function (): void {
    seedRegistry();

    $this->currency = strtoupper((string) config('orbito.currency.base'));
    $this->admin = userWithRole(RoleKey::Admin);
    $this->learner = User::factory()->withRole(RoleKey::Student)->create();

    $this->gateway = PaymentGatewayAccount::create([
        'gateway' => Gateway::Fake,
        'credentials' => ['key' => 'sk_test_provider_refunds'],
        'webhook_secret' => PROVIDER_REFUND_SECRET,
        'is_active' => true,
    ]);

    $this->poetry = courseWithCurriculum(Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]), [1]);
    $product = Product::factory()->pricedAt(4_900, $this->currency)
        ->create(['purchasable_id' => $this->poetry->id, 'title' => 'Poetry']);

    $this->order = boughtThroughFake($this->learner, $product, $this->currency);
    $this->payment = $this->order->payments()->sole();
});

/** Placed, handed to the test gateway, captured, granted. */
function boughtThroughFake(User $learner, Product $product, string $currency): Order
{
    $cart = Cart::create(['user_id' => $learner->id, 'currency' => $currency]);
    $cart->items()->create(['product_id' => $product->id]);

    $order = app(PlaceOrder::class)->handle($learner, $cart->load('items.product.prices'));
    app(InitiatePayment::class)->handle($order, Gateway::Fake);

    $payment = $order->payments()->firstOrFail();
    app(CapturePayment::class)->handle($payment, new WebhookEvent(
        id: 'evt_capture_'.$order->id,
        type: 'payment.captured',
        externalPaymentId: $payment->external_id,
        amountMinor: $payment->amount_minor,
        currency: $payment->currency,
        payload: [],
    ));

    return $order->refresh();
}

/**
 * A signed refund report from the test gateway, handled as the route handles it.
 *
 * @param  array<string, mixed>  $refund
 */
function refundReported(Payment $payment, array $refund, string $eventId, string $type = 'refund.updated'): PaymentEvent
{
    $body = json_encode([
        'id' => $eventId,
        'type' => $type,
        'payment_id' => $payment->external_id,
        'refund' => $refund + ['currency' => $payment->currency],
    ], JSON_THROW_ON_ERROR);

    return app(HandleWebhook::class)->handle(
        Request::create('/webhooks/payments/fake', 'POST', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => hash_hmac('sha256', $body, PROVIDER_REFUND_SECRET),
        ], $body),
        Gateway::Fake,
    );
}

function accessNow(User $learner, Course $course): ?EnrollmentStatus
{
    return Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->first()?->status;
}

/* ------------------------------------------------ refunds asked for here */

it('completes a pending refund when the provider settles it', function (): void {
    $this->gateway->update(['credentials' => ['key' => 'x', 'refund_behaviour' => 'pending']]);
    $refund = app(RefundOrder::class)->handle($this->admin, $this->order, 4_900, RefundMethod::Gateway, null, true);

    expect($refund->status)->toBe(RefundStatus::Pending);

    $event = refundReported($this->payment, [
        'id' => $refund->external_id,
        'amount_minor' => 4_900,
        'status' => 'completed',
        'reference' => $refund->uuid,
    ], 'evt_settled');

    expect($refund->refresh()->status)->toBe(RefundStatus::Completed)
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(accessNow($this->learner, $this->poetry))->toBe(EnrollmentStatus::Cancelled)
        ->and($event->processed_at)->not->toBeNull()
        ->and(Refund::count())->toBe(1);
});

/*
 * The window: Stripe's webhook can land before RefundOrder has written the id
 * Stripe returned. Matching on that id alone would read our own refund as a
 * stranger's and record the money twice.
 */
it('finds its own refund by the reference it sent, before the provider id is stored', function (): void {
    $refund = app(ClaimRefund::class)->handle($this->admin, $this->order, 1_000, RefundMethod::Gateway, null, true);

    expect($refund->external_id)->toBeNull();

    refundReported($this->payment, [
        'id' => 're_arrived_first',
        'amount_minor' => 1_000,
        'status' => 'completed',
        'reference' => $refund->uuid,
    ], 'evt_early');

    expect(Refund::count())->toBe(1)
        ->and($refund->refresh()->status)->toBe(RefundStatus::Completed)
        ->and($refund->external_id)->toBe('re_arrived_first')
        ->and($this->order->refresh()->status)->toBe(OrderStatus::PartiallyRefunded);
});

it('fails a pending refund the provider failed, and frees its amount', function (): void {
    $this->gateway->update(['credentials' => ['key' => 'x', 'refund_behaviour' => 'pending']]);
    $refund = app(RefundOrder::class)->handle($this->admin, $this->order, 4_900, RefundMethod::Gateway, null, true);

    refundReported($this->payment, [
        'id' => $refund->external_id,
        'amount_minor' => 4_900,
        'status' => 'failed',
        'reference' => $refund->uuid,
        'failure_reason' => 'expired_or_canceled_card',
    ], 'evt_failed', 'refund.failed');

    expect($refund->refresh()->status)->toBe(RefundStatus::Failed)
        ->and($refund->failure_reason)->toBe('expired_or_canceled_card')
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(accessNow($this->learner, $this->poetry))->toBe(EnrollmentStatus::Active);

    // Nothing went back, so all of it can still be.
    $this->gateway->update(['credentials' => ['key' => 'x']]);

    expect(app(RefundOrder::class)->handle($this->admin, $this->order->refresh(), 4_900, RefundMethod::Gateway, null, true)->status)
        ->toBe(RefundStatus::Completed);
});

/* ---------------------------------------- refunds made in the dashboard */

it('records a full refund made in the provider dashboard, and takes away what the order granted', function (): void {
    Event::fake([RefundIssued::class]);

    $event = refundReported($this->payment, [
        'id' => 're_dashboard_full',
        'amount_minor' => 4_900,
        'status' => 'completed',
    ], 'evt_dashboard');

    $refund = Refund::sole();

    expect($refund->status)->toBe(RefundStatus::Completed)
        ->and($refund->method)->toBe(RefundMethod::Gateway)
        ->and($refund->payment_id)->toBe($this->payment->id)
        ->and($refund->external_id)->toBe('re_dashboard_full')
        // Nobody here asked for it.
        ->and($refund->requested_by)->toBeNull()
        ->and($refund->reason)->toBe('Refunded through Test gateway.')
        ->and($refund->revokes_access)->toBeTrue()
        // Split across the lines like any other, so revenue still adds up.
        ->and((int) $refund->lines()->sum('amount_minor'))->toBe(4_900)
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($this->order->refunded_minor)->toBe(4_900)
        ->and(accessNow($this->learner, $this->poetry))->toBe(EnrollmentStatus::Cancelled)
        ->and($event->processed_at)->not->toBeNull();

    Event::assertDispatched(RefundIssued::class, fn (RefundIssued $e): bool => $e->fullyRefunded);
});

it('records a partial dashboard refund without touching access', function (): void {
    refundReported($this->payment, [
        'id' => 're_dashboard_part',
        'amount_minor' => 1_000,
        'status' => 'completed',
    ], 'evt_part');

    expect(Refund::sole()->revokes_access)->toBeFalse()
        ->and($this->order->refresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and(accessNow($this->learner, $this->poetry))->toBe(EnrollmentStatus::Active);
});

/*
 * Stripe describes one refund in several events, in any order. A late,
 * stale `pending` after the settlement is not a reversal.
 */
it('records a dashboard refund once, however many events describe it', function (): void {
    $refund = ['id' => 're_described_four_times', 'amount_minor' => 2_000];

    refundReported($this->payment, $refund + ['status' => 'pending'], 'evt_created', 'refund.created');
    refundReported($this->payment, $refund + ['status' => 'completed'], 'evt_updated');
    refundReported($this->payment, $refund + ['status' => 'pending'], 'evt_stale', 'refund.created');
    refundReported($this->payment, $refund + ['status' => 'completed'], 'evt_updated_again');

    expect(Refund::sole()->status)->toBe(RefundStatus::Completed)
        ->and($this->order->refresh()->refunded_minor)->toBe(2_000)
        ->and(PaymentEvent::whereNull('processed_at')->count())->toBe(0);
});

it('holds a pending dashboard refund against what is left', function (): void {
    refundReported($this->payment, [
        'id' => 're_still_pending',
        'amount_minor' => 4_900,
        'status' => 'pending',
    ], 'evt_pending', 'refund.created');

    expect(Refund::sole()->status)->toBe(RefundStatus::Pending)
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Paid);

    // The money is on its way back; nobody here may give it back again.
    expect(fn () => app(RefundOrder::class)->handle($this->admin, $this->order, 1, RefundMethod::External, null, false))
        ->toThrow(RefundRejected::class);
});

/* ------------------------------------------------------- for a person */

it('leaves a dashboard refund larger than what is left for a person', function (): void {
    Log::spy();

    // Recorded by hand as well — what the refund dialog used to tell staff to do.
    app(RefundOrder::class)->handle($this->admin, $this->order, 4_900, RefundMethod::External, 'Refunded in Stripe.', false);

    $event = refundReported($this->payment, [
        'id' => 're_recorded_twice',
        'amount_minor' => 4_900,
        'status' => 'completed',
    ], 'evt_twice');

    expect(Refund::count())->toBe(1)
        ->and($this->order->refresh()->refunded_minor)->toBe(4_900)
        ->and($event->processed_at)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'left for a person'))
        ->once();
});

it('leaves a refund the provider failed after it completed for a person', function (): void {
    $refund = app(RefundOrder::class)->handle($this->admin, $this->order, 1_000, RefundMethod::Gateway, null, true);

    expect($refund->status)->toBe(RefundStatus::Completed);

    $event = refundReported($this->payment, [
        'id' => $refund->external_id,
        'amount_minor' => 1_000,
        'status' => 'failed',
        'reference' => $refund->uuid,
    ], 'evt_reversed', 'refund.failed');

    // Undoing it would re-decide access and revenue nobody asked to re-decide.
    expect($refund->refresh()->status)->toBe(RefundStatus::Completed)
        ->and($this->order->refresh()->refunded_minor)->toBe(1_000)
        ->and($event->processed_at)->toBeNull();
});

it('will not settle its own refund on a report of a different amount', function (): void {
    $this->gateway->update(['credentials' => ['key' => 'x', 'refund_behaviour' => 'pending']]);
    $refund = app(RefundOrder::class)->handle($this->admin, $this->order, 4_900, RefundMethod::Gateway, null, true);

    $event = refundReported($this->payment, [
        'id' => $refund->external_id,
        'amount_minor' => 100,
        'status' => 'completed',
        'reference' => $refund->uuid,
    ], 'evt_short');

    expect($refund->refresh()->status)->toBe(RefundStatus::Pending)
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($event->processed_at)->toBeNull();
});

/* ----------------------------------------------------------- refusals */

it('records nothing for a refund on a payment we never issued', function (): void {
    $stranger = new Payment(['external_id' => 'fake_never_issued', 'currency' => $this->currency]);

    $event = refundReported($stranger, [
        'id' => 're_for_a_stranger',
        'amount_minor' => 4_900,
        'status' => 'completed',
    ], 'evt_stranger');

    expect(Refund::count())->toBe(0)
        ->and($event->payment_id)->toBeNull()
        ->and($event->processed_at)->toBeNull()
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('changes nothing for a refund report with a forged signature', function (): void {
    $body = json_encode([
        'id' => 'evt_forged_refund',
        'type' => 'refund.updated',
        'payment_id' => $this->payment->external_id,
        'refund' => ['id' => 're_forged', 'amount_minor' => 4_900, 'currency' => $this->currency, 'status' => 'completed'],
    ], JSON_THROW_ON_ERROR);

    expect(fn () => app(HandleWebhook::class)->handle(
        Request::create('/webhooks/payments/fake', 'POST', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => hash_hmac('sha256', $body, 'whsec_the_attackers_own'),
        ], $body),
        Gateway::Fake,
    ))->toThrow(WebhookRejected::class);

    expect(Refund::count())->toBe(0)
        ->and(accessNow($this->learner, $this->poetry))->toBe(EnrollmentStatus::Active);
});

it('acts on nothing in a refund report missing its amount', function (): void {
    refundReported($this->payment, ['id' => 're_no_amount', 'status' => 'completed'], 'evt_incomplete');

    expect(Refund::count())->toBe(0)
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Paid);
});

/* --------------------------------------------------------- the route */

it('records a dashboard refund delivered to the webhook route, with nobody signed in', function (): void {
    $body = json_encode([
        'id' => 'evt_over_http',
        'type' => 'refund.updated',
        'payment_id' => $this->payment->external_id,
        'refund' => ['id' => 're_over_http', 'amount_minor' => 1_000, 'currency' => $this->currency, 'status' => 'completed'],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', '/api/v1/webhooks/payments/fake/'.tenant()->getTenantKey(), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => hash_hmac('sha256', $body, PROVIDER_REFUND_SECRET),
    ], $body)
        ->assertOk()
        ->assertJsonPath('data.processed', true);

    expect(Refund::sole()->external_id)->toBe('re_over_http');
});

/* -------------------------------------------------- Stripe's own shape */

/**
 * A body in Stripe's shape, signed with Stripe's scheme, read by the real
 * StripeGateway. ⚠ Written to Stripe's documented objects; no real Stripe
 * event has been through it yet.
 *
 * @param  array<string, mixed>  $object
 */
function stripeEvent(string $type, array $object): WebhookEvent
{
    $secret = 'whsec_stripe_refund_shape';
    $body = json_encode(['id' => 'evt_stripe_'.$type, 'type' => $type, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
    $timestamp = time();

    $account = PaymentGatewayAccount::create([
        'gateway' => Gateway::Stripe,
        'credentials' => ['key' => 'sk_test_shape'],
        'webhook_secret' => $secret,
        'is_active' => true,
        // Set, not left to the column default: a created model does not read
        // back defaults, and GatewayAccount takes a bool.
        'is_test_mode' => true,
    ])->toGatewayAccount();

    return (new StripeGateway)->verifyWebhook(Request::create('/webhooks/payments/stripe', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
    ], $body), $account);
}

it('reads a Stripe refund: the payment by its intent, our refund by its metadata', function (string $stripeStatus, RefundStatus $ours): void {
    $event = stripeEvent('refund.updated', [
        'id' => 're_123',
        'object' => 'refund',
        'amount' => 1_000,
        'currency' => 'bdt',
        'payment_intent' => 'pi_abc',
        'status' => $stripeStatus,
        'metadata' => ['refund_uuid' => 'our-refund-uuid'],
        'failure_reason' => null,
    ]);

    expect($event->isRefund())->toBeTrue()
        // Not `re_123`: the payment is found by the id the handoff stored.
        ->and($event->externalPaymentId)->toBe('pi_abc')
        ->and($event->refunds)->toHaveCount(1)
        ->and($event->refunds[0]->externalId)->toBe('re_123')
        ->and($event->refunds[0]->amountMinor)->toBe(1_000)
        ->and($event->refunds[0]->currency)->toBe('BDT')
        ->and($event->refunds[0]->status)->toBe($ours)
        ->and($event->refunds[0]->reference)->toBe('our-refund-uuid');
})->with([
    'succeeded' => ['succeeded', RefundStatus::Completed],
    'pending' => ['pending', RefundStatus::Pending],
    'requires_action' => ['requires_action', RefundStatus::Pending],
    'failed' => ['failed', RefundStatus::Failed],
    'canceled' => ['canceled', RefundStatus::Failed],
]);

it('reports nothing for a Stripe refund in a status it does not know', function (): void {
    $event = stripeEvent('refund.updated', [
        'id' => 're_odd',
        'object' => 'refund',
        'amount' => 1_000,
        'currency' => 'bdt',
        'payment_intent' => 'pi_abc',
        'status' => 'something_new',
    ]);

    expect($event->refunds)->toBe([]);
});

it('still reads a Stripe payment by its own id, with no refunds', function (): void {
    $event = stripeEvent('payment_intent.succeeded', [
        'id' => 'pi_paid',
        'object' => 'payment_intent',
        'amount' => 4_900,
        'currency' => 'bdt',
    ]);

    expect($event->externalPaymentId)->toBe('pi_paid')
        ->and($event->amountMinor)->toBe(4_900)
        ->and($event->refunds)->toBe([]);
});
