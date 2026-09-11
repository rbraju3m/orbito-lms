<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Actions\CapturePayment;
use App\Domain\Commerce\Actions\HandleWebhook;
use App\Domain\Commerce\Actions\InitiatePayment;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Actions\RefundOrder;
use App\Domain\Commerce\Data\WebhookEvent;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\RefundMethod;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\PaymentEvent;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Commerce\Models\Product;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;

/*
 * The refund-reports screen's API: what the webhook left for a person, and a
 * way to say it has been dealt with. What matters is what does NOT appear — a
 * report that settled, an event about a payment we never issued — and that a
 * resolution is recorded once, by somebody allowed to refund.
 */

const REPORTS_SECRET = 'whsec_refund_reports';

beforeEach(function (): void {
    seedRegistry();

    $this->currency = strtoupper((string) config('orbito.currency.base'));
    $this->admin = userWithRole(RoleKey::Admin);
    $learner = User::factory()->withRole(RoleKey::Student)->create();

    PaymentGatewayAccount::create([
        'gateway' => Gateway::Fake,
        'credentials' => ['key' => 'sk_test_reports'],
        'webhook_secret' => REPORTS_SECRET,
        'is_active' => true,
    ]);

    $course = courseWithCurriculum(Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]), [1]);
    $product = Product::factory()->pricedAt(4_900, $this->currency)
        ->create(['purchasable_id' => $course->id, 'title' => 'Poetry']);

    $cart = Cart::create(['user_id' => $learner->id, 'currency' => $this->currency]);
    $cart->items()->create(['product_id' => $product->id]);

    $this->order = app(PlaceOrder::class)->handle($learner, $cart->load('items.product.prices'));
    app(InitiatePayment::class)->handle($this->order, Gateway::Fake);

    $this->payment = $this->order->payments()->sole();
    app(CapturePayment::class)->handle($this->payment, new WebhookEvent(
        id: 'evt_reports_capture',
        type: 'payment.captured',
        externalPaymentId: $this->payment->external_id,
        amountMinor: $this->payment->amount_minor,
        currency: $this->payment->currency,
        payload: [],
    ));

    $this->order->refresh();
});

/** A signed, completed refund report from the test gateway, handled as the route handles it. */
function reportToBooks(Payment $payment, string $eventId, int $amountMinor, string $refundId): PaymentEvent
{
    $body = json_encode([
        'id' => $eventId,
        'type' => 'refund.updated',
        'payment_id' => $payment->external_id,
        'refund' => [
            'id' => $refundId,
            'amount_minor' => $amountMinor,
            'currency' => $payment->currency,
            'status' => 'completed',
        ],
    ], JSON_THROW_ON_ERROR);

    return app(HandleWebhook::class)->handle(
        Request::create('/webhooks/payments/fake', 'POST', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => hash_hmac('sha256', $body, REPORTS_SECRET),
        ], $body),
        Gateway::Fake,
    );
}

/** The classic: refunded in the provider's dashboard AND recorded here by hand. */
function doubleRecorded(User $admin, Order $order, Payment $payment): PaymentEvent
{
    app(RefundOrder::class)->handle($admin, $order, 4_900, RefundMethod::External, 'Refunded in the dashboard.', false);

    return reportToBooks($payment, 'evt_double', 4_900, 're_double');
}

it('lists what the webhook left for a person: what happened, what to check, and the order', function (): void {
    $event = doubleRecorded($this->admin, $this->order, $this->payment);

    expect($event->needs_attention)->toBeTrue()
        ->and($event->processed_at)->toBeNull();

    $this->actingAs($this->admin)->getJson('/api/v1/admin/refund-reports')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $event->id)
        ->assertJsonPath('data.0.items.0.reason', 'more_than_left')
        ->assertJsonPath('data.0.items.0.reason_label', 'More than the order has left to refund')
        ->assertJsonPath('data.0.items.0.amount_minor', 4_900)
        ->assertJsonPath('data.0.items.0.provider_refund_id', 're_double')
        ->assertJsonPath('data.0.items.0.reported_status', 'completed')
        ->assertJsonPath('data.0.order.id', $this->order->uuid)
        ->assertJsonPath('data.0.order.number', $this->order->number)
        // The provider's raw object never reaches the screen.
        ->assertJsonMissingPath('data.0.payload');
});

it('never lists a report that settled, or one about a payment we never issued', function (): void {
    // A partial dashboard refund: recorded, so settled.
    reportToBooks($this->payment, 'evt_settles', 1_000, 're_settles');

    // Unprocessed — but noise, not work.
    $stranger = new Payment(['external_id' => 'fake_never_issued', 'currency' => $this->currency]);
    reportToBooks($stranger, 'evt_stranger', 1_000, 're_stranger');

    $this->actingAs($this->admin)->getJson('/api/v1/admin/refund-reports')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('resolves a report with a note, once, and drops it from the list', function (): void {
    $event = doubleRecorded($this->admin, $this->order, $this->payment);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/refund-reports/{$event->id}/resolve", ['note' => 'Recorded by hand as well — nothing owed.'])
        ->assertOk()
        ->assertJsonPath('data.resolution_note', 'Recorded by hand as well — nothing owed.');

    $resolvedAt = $event->refresh()->resolved_at;

    expect($event->resolved_by)->toBe($this->admin->id);

    // The first resolution stands: who looked first, and what they found.
    $this->travel(1)->minutes();
    $this->actingAs(userWithRole(RoleKey::Admin))
        ->postJson("/api/v1/admin/refund-reports/{$event->id}/resolve", ['note' => 'Second look.'])
        ->assertOk();

    expect($event->refresh()->resolved_by)->toBe($this->admin->id)
        ->and($event->resolution_note)->toBe('Recorded by hand as well — nothing owed.')
        ->and($event->resolved_at->equalTo($resolvedAt))->toBeTrue();

    $this->actingAs($this->admin)->getJson('/api/v1/admin/refund-reports')->assertJsonCount(0, 'data');
});

it('404s resolving an event that never needed a person', function (): void {
    $settled = reportToBooks($this->payment, 'evt_fine', 1_000, 're_fine');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/refund-reports/{$settled->id}/resolve")
        ->assertNotFound();
});

it('refuses anybody who cannot refund', function (string $role): void {
    $event = doubleRecorded($this->admin, $this->order, $this->payment);
    $actor = userWithRole(RoleKey::from($role));

    $this->actingAs($actor)->getJson('/api/v1/admin/refund-reports')->assertForbidden();
    $this->actingAs($actor)->postJson("/api/v1/admin/refund-reports/{$event->id}/resolve")->assertForbidden();

    expect($event->refresh()->resolved_at)->toBeNull();
})->with(['instructor', 'staff', 'student']);

it('validates the note', function (): void {
    $event = doubleRecorded($this->admin, $this->order, $this->payment);

    expect($this->actingAs($this->admin)
        ->postJson("/api/v1/admin/refund-reports/{$event->id}/resolve", ['note' => str_repeat('x', 501)])
        ->assertStatus(422))->toBeApiError('validation_failed');
});
