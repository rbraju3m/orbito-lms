<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Actions\HandleWebhook;
use App\Domain\Commerce\Actions\InitiatePayment;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Events\PaymentCaptured;
use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Commerce\Exceptions\GatewayUnavailable;
use App\Domain\Commerce\Exceptions\WebhookRejected;
use App\Domain\Commerce\Gateways\FakeGateway;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\PaymentEvent;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Commerce\Models\Product;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/*
 * The money path, end to end, against FakeGateway.
 *
 * Phase 10 rests on four claims that had never executed: a bad signature is
 * refused, a replayed delivery grants once, a short capture grants nothing,
 * and a forged success grants nothing. Everything built on top of this — the
 * HTTP surface, the checkout UI — assumes they hold, so they are proven here
 * before any of it exists.
 *
 * The gateway is fake but the CHECKS are not: FakeGateway signs with a real
 * HMAC over the raw body, which is the same verification shape Stripe's is.
 */

const WEBHOOK_SECRET = 'whsec_money_path_test';

beforeEach(function (): void {
    seedRegistry();

    PaymentGatewayAccount::create([
        'gateway' => Gateway::Fake,
        'credentials' => ['key' => 'sk_test_money_path'],
        'webhook_secret' => WEBHOOK_SECRET,
        'is_active' => true,
        'is_test_mode' => true,
    ]);

    $this->course = courseWithCurriculum(
        Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]),
        [1],
    );

    $this->product = Product::factory()
        ->pricedAt(4900)
        ->create(['purchasable_id' => $this->course->id, 'title' => $this->course->title]);

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
});

/** A cart holding the priced course, ready for checkout. */
function cartFor(User $user, Product $product): Cart
{
    $cart = Cart::create(['user_id' => $user->id, 'currency' => 'USD']);
    $cart->items()->create(['product_id' => $product->id]);

    return $cart->load('items.product.prices');
}

/** Places an order and hands it to the gateway. Returns the payment. */
function orderAwaitingPayment(User $user, Product $product): Payment
{
    $order = app(PlaceOrder::class)->handle($user, cartFor($user, $product));
    app(InitiatePayment::class)->handle($order, Gateway::Fake);

    return Payment::where('order_id', $order->id)->firstOrFail();
}

/**
 * A webhook delivery. `$secret` is what it is SIGNED with — pass a different
 * one to forge, and null to omit the header entirely.
 *
 * @param  array<string, mixed>  $payload
 */
function webhook(array $payload, ?string $secret = WEBHOOK_SECRET): Request
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $server = $secret === null
        ? []
        : ['HTTP_'.str_replace('-', '_', strtoupper(FakeGateway::SIGNATURE_HEADER)) => hash_hmac('sha256', $body, $secret)];

    return Request::create('/webhooks/payments/fake', 'POST', [], [], [], $server, $body);
}

/**
 * A success delivery for a payment.
 *
 * @return array<string, mixed>
 */
function capturedPayload(Payment $payment, ?int $amountMinor = null, string $eventId = 'evt_1'): array
{
    return [
        'id' => $eventId,
        'type' => 'payment.captured',
        'payment_id' => $payment->external_id,
        'amount_minor' => $amountMinor ?? $payment->amount_minor,
        'currency' => $payment->currency,
    ];
}

/* ---------------------------------------------------------------- the path */

it('grants access only when a verified webhook says the money moved', function (): void {
    Event::fake([PaymentCaptured::class]);

    $payment = orderAwaitingPayment($this->student, $this->product);

    // Handed off, and deliberately granting nothing yet.
    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->external_id)->toStartWith('fake_')
        ->and($payment->order->status)->toBe(OrderStatus::AwaitingPayment)
        ->and(Enrollment::where('user_id', $this->student->id)->exists())->toBeFalse();

    app(HandleWebhook::class)->handle(webhook(capturedPayload($payment)), Gateway::Fake);

    $payment->refresh();
    $enrollment = Enrollment::where('user_id', $this->student->id)->first();

    expect($payment->status)->toBe(PaymentStatus::Captured)
        ->and($payment->captured_at)->not->toBeNull()
        ->and($payment->order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($payment->order->paid_at)->not->toBeNull()
        ->and($enrollment)->not->toBeNull()
        ->and($enrollment->course_id)->toBe($this->course->id)
        // The order it was bought under, not a flag bolted onto a free enrol.
        ->and($enrollment->source)->toBe(EnrollmentSource::Purchase)
        ->and($enrollment->source_id)->toBe($payment->order_id);

    // The event other domains hang off. It must carry a settled payment.
    Event::assertDispatched(
        PaymentCaptured::class,
        fn (PaymentCaptured $e): bool => $e->payment->status === PaymentStatus::Captured,
    );

    // Recorded as processed, so the reconciliation sweep leaves it alone.
    expect(PaymentEvent::where('external_event_id', 'evt_1')->first())
        ->signature_verified->toBeTrue()
        ->processed_at->not->toBeNull();
});

it('prices the order from the database, never from the caller', function (): void {
    // The cart deliberately stores no price. If the order took its figures
    // from anywhere but product_prices, this is where it would show.
    $order = app(PlaceOrder::class)->handle($this->student, cartFor($this->student, $this->product));

    expect($order->total_minor)->toBe(4900)
        ->and($order->subtotal_minor)->toBe(4900)
        ->and($order->items)->toHaveCount(1)
        ->and($order->items->first()->unit_amount_minor)->toBe(4900)
        // Snapshotted, so editing the product later cannot rewrite the charge.
        ->and($order->items->first()->title_snapshot)->toBe($this->product->title);

    $this->product->prices()->first()->update(['amount_minor' => 100]);

    expect($order->fresh()->total_minor)->toBe(4900);
});

/* -------------------------------------------------- 1. signature rejection */

it('refuses a webhook signed with the wrong secret, and records nothing', function (): void {
    $payment = orderAwaitingPayment($this->student, $this->product);
    $forged = webhook(capturedPayload($payment), secret: 'whsec_the_attackers_own');

    expect(fn () => app(HandleWebhook::class)->handle($forged, Gateway::Fake))
        ->toThrow(WebhookRejected::class);

    // Nothing below verification may run — not even the audit row, because we
    // have no verified reason to associate the delivery with this payment.
    expect(PaymentEvent::count())->toBe(0)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->order->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and(Enrollment::where('user_id', $this->student->id)->exists())->toBeFalse();
});

it('refuses a webhook with no signature at all', function (): void {
    $payment = orderAwaitingPayment($this->student, $this->product);

    expect(fn () => app(HandleWebhook::class)->handle(
        webhook(capturedPayload($payment), secret: null),
        Gateway::Fake,
    ))->toThrow(WebhookRejected::class);

    expect(Enrollment::where('user_id', $this->student->id)->exists())->toBeFalse();
});

it('refuses a body altered after it was signed', function (): void {
    $payment = orderAwaitingPayment($this->student, $this->product);

    // Sign the honest payload, then swap the body underneath the signature —
    // the shape of every tampering attempt against an HMAC over a raw body.
    $signed = webhook(capturedPayload($payment));
    $tampered = Request::create(
        '/webhooks/payments/fake',
        'POST',
        [],
        [],
        [],
        ['HTTP_X_FAKE_SIGNATURE' => $signed->header(FakeGateway::SIGNATURE_HEADER)],
        json_encode(capturedPayload($payment, eventId: 'evt_swapped'), JSON_THROW_ON_ERROR),
    );

    expect(fn () => app(HandleWebhook::class)->handle($tampered, Gateway::Fake))
        ->toThrow(WebhookRejected::class);

    expect(PaymentEvent::count())->toBe(0);
});

/* --------------------------------------------------- 2. replay idempotency */

it('grants once however many times the same event is delivered', function (): void {
    $payment = orderAwaitingPayment($this->student, $this->product);
    $delivery = fn (): Request => webhook(capturedPayload($payment));

    $first = app(HandleWebhook::class)->handle($delivery(), Gateway::Fake);
    $second = app(HandleWebhook::class)->handle($delivery(), Gateway::Fake);
    $third = app(HandleWebhook::class)->handle($delivery(), Gateway::Fake);

    // The replay returns the stored event rather than throwing: a retry is
    // normal provider behaviour and must answer 2xx, not an error.
    expect($second->id)->toBe($first->id)
        ->and($third->id)->toBe($first->id)
        ->and(PaymentEvent::count())->toBe(1)
        ->and(Enrollment::where('user_id', $this->student->id)->count())->toBe(1)
        ->and(Payment::where('status', PaymentStatus::Captured)->count())->toBe(1);
});

it('will not capture twice for two different event ids', function (): void {
    // Distinct ids slip past the idempotency key, so the second line of
    // defence is the payment already being settled.
    $payment = orderAwaitingPayment($this->student, $this->product);

    app(HandleWebhook::class)->handle(webhook(capturedPayload($payment, eventId: 'evt_a')), Gateway::Fake);
    $capturedAt = $payment->refresh()->captured_at;

    app(HandleWebhook::class)->handle(webhook(capturedPayload($payment, eventId: 'evt_b')), Gateway::Fake);

    expect(PaymentEvent::count())->toBe(2)
        ->and(Enrollment::where('user_id', $this->student->id)->count())->toBe(1)
        ->and($payment->refresh()->captured_at->equalTo($capturedAt))->toBeTrue();
});

it('ignores a late failure for an already captured payment', function (): void {
    $payment = orderAwaitingPayment($this->student, $this->product);

    app(HandleWebhook::class)->handle(webhook(capturedPayload($payment)), Gateway::Fake);
    app(HandleWebhook::class)->handle(webhook([
        'id' => 'evt_late_failure',
        'type' => 'payment.failed',
        'payment_id' => $payment->external_id,
    ]), Gateway::Fake);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Captured)
        ->and($payment->order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(Enrollment::where('user_id', $this->student->id)->count())->toBe(1);
});

/* ------------------------------------------------------- 3. amount mismatch */

it('grants nothing when the gateway captured less than the order total', function (): void {
    $payment = orderAwaitingPayment($this->student, $this->product);

    app(HandleWebhook::class)->handle(
        webhook(capturedPayload($payment, amountMinor: 100)),
        Gateway::Fake,
    );

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('Captured amount is less than the order total.')
        // The order must NOT read paid, or the sweep would consider it done.
        ->and($payment->order->refresh()->status)->not->toBe(OrderStatus::Paid)
        ->and($payment->order->paid_at)->toBeNull()
        ->and(Enrollment::where('user_id', $this->student->id)->exists())->toBeFalse();
});

it('accepts an overpayment', function (): void {
    // Deliberate: only a SHORT capture is a problem. Refusing an overpayment
    // would strand money the learner has already parted with.
    $payment = orderAwaitingPayment($this->student, $this->product);

    app(HandleWebhook::class)->handle(
        webhook(capturedPayload($payment, amountMinor: 5000)),
        Gateway::Fake,
    );

    expect($payment->refresh()->status)->toBe(PaymentStatus::Captured)
        ->and(Enrollment::where('user_id', $this->student->id)->count())->toBe(1);
});

it('grants nothing when the captured currency is not the order currency', function (): void {
    $payment = orderAwaitingPayment($this->student, $this->product);

    app(HandleWebhook::class)->handle(webhook([
        'id' => 'evt_wrong_currency',
        'type' => 'payment.captured',
        'payment_id' => $payment->external_id,
        // Enough NGN to look like the total, in a currency worth far less.
        'amount_minor' => 4900,
        'currency' => 'NGN',
    ]), Gateway::Fake);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('Captured currency does not match the order.')
        ->and(Enrollment::where('user_id', $this->student->id)->exists())->toBeFalse();
});

/* --------------------------------------------------------- 4. forged success */

it('grants nothing for a payment id we never issued', function (): void {
    orderAwaitingPayment($this->student, $this->product);

    // Correctly signed — the attacker is assumed to hold the secret here, so
    // that the ONLY thing refusing them is the unknown payment id.
    app(HandleWebhook::class)->handle(webhook([
        'id' => 'evt_invented',
        'type' => 'payment.captured',
        'payment_id' => 'fake_ipaidipromise',
        'amount_minor' => 4900,
        'currency' => 'USD',
    ]), Gateway::Fake);

    // Stored for an operator to see, acted on never.
    expect(PaymentEvent::where('external_event_id', 'evt_invented')->first())
        ->payment_id->toBeNull()
        ->processed_at->toBeNull();

    expect(Enrollment::where('user_id', $this->student->id)->exists())->toBeFalse()
        ->and(Order::where('status', OrderStatus::Paid)->exists())->toBeFalse()
        ->and(Payment::where('status', PaymentStatus::Captured)->exists())->toBeFalse();
});

it('does not let one learner claim another learners payment', function (): void {
    $payer = orderAwaitingPayment($this->student, $this->product);

    $attacker = User::factory()->withRole(RoleKey::Student)->create();
    $attackerCourse = courseWithCurriculum(
        Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]),
        [1],
    );
    $attackerProduct = Product::factory()
        ->pricedAt(4900)
        ->create(['purchasable_id' => $attackerCourse->id]);
    $attackerPayment = orderAwaitingPayment($attacker, $attackerProduct);

    // The attacker names the real payer's external id on their own order's
    // event. The lookup is by the id WE issued, so it resolves to the payer.
    app(HandleWebhook::class)->handle(webhook([
        'id' => 'evt_claimed',
        'type' => 'payment.captured',
        'payment_id' => $payer->external_id,
        'amount_minor' => 4900,
        'currency' => 'USD',
    ]), Gateway::Fake);

    expect($attackerPayment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($attackerPayment->order->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and(Enrollment::where('user_id', $attacker->id)->exists())->toBeFalse()
        // The genuine payer is enrolled, which is correct — they paid.
        ->and(Enrollment::where('user_id', $this->student->id)->exists())->toBeTrue();
});

it('refuses a malformed payload even when correctly signed', function (): void {
    expect(fn () => app(HandleWebhook::class)->handle(
        webhook(['type' => 'payment.captured']),
        Gateway::Fake,
    ))->toThrow(WebhookRejected::class);

    expect(PaymentEvent::count())->toBe(0);
});

/* ------------------------------------------------------ the surrounding gates */

it('refuses to hand off an order that is already paid', function (): void {
    $payment = orderAwaitingPayment($this->student, $this->product);
    app(HandleWebhook::class)->handle(webhook(capturedPayload($payment)), Gateway::Fake);

    expect(fn () => app(InitiatePayment::class)->handle($payment->order->refresh(), Gateway::Fake))
        ->toThrow(CheckoutRejected::class);
});

it('refuses to sell a course the learner already owns', function (): void {
    $payment = orderAwaitingPayment($this->student, $this->product);
    app(HandleWebhook::class)->handle(webhook(capturedPayload($payment)), Gateway::Fake);

    expect(fn () => app(PlaceOrder::class)->handle($this->student, cartFor($this->student, $this->product)))
        ->toThrow(CheckoutRejected::class);
});

it('refuses a gateway the academy has not connected', function (): void {
    PaymentGatewayAccount::query()->update(['is_active' => false]);

    $order = app(PlaceOrder::class)->handle($this->student, cartFor($this->student, $this->product));

    expect(fn () => app(InitiatePayment::class)->handle($order, Gateway::Fake))
        ->toThrow(GatewayUnavailable::class);
});
