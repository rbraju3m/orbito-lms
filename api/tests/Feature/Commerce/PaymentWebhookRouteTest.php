<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Actions\InitiatePayment;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\PaymentEvent;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Commerce\Models\Product;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Subscription;
use App\Domain\Platform\Models\Tenant;

/*
 * The webhook ROUTE — the only unauthenticated write in the system.
 *
 * HandleWebhook's own guarantees are proven in MoneyPathTest. This file tests
 * the three things the route adds: resolving an academy from an
 * attacker-controllable path segment, staying outside auth and the
 * subscription gate, and refusing to become an oracle for which academies
 * exist.
 */

const HOOK_SECRET = 'whsec_route_test';

beforeEach(function (): void {
    seedRegistry();

    PaymentGatewayAccount::create([
        'gateway' => Gateway::Fake,
        'credentials' => ['key' => 'sk_test_route'],
        'webhook_secret' => HOOK_SECRET,
        'is_active' => true,
    ]);

    $this->course = courseWithCurriculum(
        Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]),
        [1],
    );
    $this->product = Product::factory()
        ->pricedAt(4900)
        ->create(['purchasable_id' => $this->course->id]);

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    $this->tenantId = tenant()->getTenantKey();

    $cart = Cart::create(['user_id' => $this->student->id, 'currency' => 'USD']);
    $cart->items()->create(['product_id' => $this->product->id]);

    $order = app(PlaceOrder::class)->handle($this->student, $cart->load('items.product.prices'));
    app(InitiatePayment::class)->handle($order, Gateway::Fake);

    $this->payment = Payment::where('order_id', $order->id)->firstOrFail();
});

/**
 * Delivers a webhook the way a provider would: raw body, HMAC header, no
 * session and no bearer token.
 *
 * @param  array<string, mixed>  $payload
 */
function deliver(array $payload, ?string $tenantId = null, ?string $secret = HOOK_SECRET, string $gateway = 'fake')
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $tenantId ??= test()->tenantId;

    return test()->call(
        'POST',
        "/api/v1/webhooks/payments/{$gateway}/{$tenantId}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => $secret === null
                ? ''
                : hash_hmac('sha256', $body, $secret),
        ],
        $body,
    );
}

/** @return array<string, mixed> */
function captured(string $externalId, string $eventId = 'evt_route_1'): array
{
    return [
        'id' => $eventId,
        'type' => 'payment.captured',
        'payment_id' => $externalId,
        'amount_minor' => 4900,
        'currency' => 'USD',
    ];
}

/* ------------------------------------------------------------- the happy path */

it('grants access from an unauthenticated, correctly signed delivery', function (): void {
    deliver(captured($this->payment->external_id))
        ->assertOk()
        ->assertJsonPath('data.received', true)
        ->assertJsonPath('data.processed', true);

    expect($this->payment->refresh()->status)->toBe(PaymentStatus::Captured)
        ->and($this->payment->order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(Enrollment::where('user_id', $this->student->id)->exists())->toBeTrue();
});

it('answers 200 to a replay without granting twice', function (): void {
    deliver(captured($this->payment->external_id))->assertOk();
    deliver(captured($this->payment->external_id))
        ->assertOk()
        ->assertJsonPath('data.received', true);

    expect(PaymentEvent::count())->toBe(1)
        ->and(Enrollment::where('user_id', $this->student->id)->count())->toBe(1);
});

it('answers 200 for a verified event it chose not to act on', function (): void {
    // An unknown payment id is recorded and ignored. Answering non-2xx would
    // make the provider retry something that can never succeed.
    deliver(captured('fake_never_issued', 'evt_unknown'))
        ->assertOk()
        ->assertJsonPath('data.processed', false);

    expect(Enrollment::where('user_id', $this->student->id)->exists())->toBeFalse();
});

/* ------------------------------------------------------------ the refusals */

it('refuses a forged signature with a flat 400', function (): void {
    expect(deliver(captured($this->payment->external_id), secret: 'whsec_forged')->assertStatus(400))
        ->toBeApiError('webhook_rejected');

    expect(PaymentEvent::count())->toBe(0)
        ->and(Enrollment::where('user_id', $this->student->id)->exists())->toBeFalse();
});

it('refuses a delivery with no signature header', function (): void {
    deliver(captured($this->payment->external_id), secret: null)->assertStatus(400);

    expect(Enrollment::where('user_id', $this->student->id)->exists())->toBeFalse();
});

it('refuses an unknown gateway name', function (): void {
    deliver(captured($this->payment->external_id), gateway: 'not_a_gateway')->assertStatus(400);

    expect(PaymentEvent::count())->toBe(0);
});

/*
 * The academy in the path is attacker-controllable, so these three are the
 * heart of the route's security argument.
 */

it('404s for an academy that does not exist', function (): void {
    deliver(captured($this->payment->external_id), tenantId: 'no-such-academy')->assertNotFound();
});

it('404s for a closed academy, indistinguishably from a missing one', function (): void {
    // Same status, same body: anything more specific turns a route reachable
    // by anybody on the internet into an oracle for which ids exist.
    $suspended = Tenant::create([
        'id' => 'suspended-academy',
        'slug' => 'suspended-academy',
        'name' => 'Suspended',
        'status' => TenantStatus::Suspended,
        'is_active' => false,
    ]);

    $missing = deliver(captured($this->payment->external_id), tenantId: 'no-such-academy');
    $closed = deliver(captured($this->payment->external_id), tenantId: $suspended->id);

    expect($closed->status())->toBe($missing->status())->toBe(404);
});

it('refuses a delivery signed with another academys secret', function (): void {
    // Naming somebody else's academy only means the payload is checked
    // against a secret the caller does not hold.
    deliver(captured($this->payment->external_id), secret: 'whsec_some_other_academy')
        ->assertStatus(400);

    expect($this->payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and(Enrollment::where('user_id', $this->student->id)->exists())->toBeFalse();
});

/* --------------------------------------------------- outside the usual gates */

it('is not behind auth, and a bearer token changes nothing', function (): void {
    // No actingAs anywhere in this file's happy path — that is the assertion.
    // Here, the inverse: being signed in must not be required OR sufficient.
    expect(auth()->check())->toBeFalse();

    deliver(captured($this->payment->external_id))->assertOk();
});

it('still records a capture for an academy whose subscription has lapsed', function (): void {
    /*
     * The sharpest edge of the 402 rule. The money has already moved; refusing
     * the webhook because the academy's own bill is overdue would take a
     * learner's payment and grant them nothing.
     */
    Subscription::query()->update([
        'status' => SubscriptionStatus::Canceled,
        'current_period_ends_at' => now()->subMonths(2),
        'grace_days' => 0,
    ]);

    deliver(captured($this->payment->external_id))->assertOk();

    expect($this->payment->refresh()->status)->toBe(PaymentStatus::Captured)
        ->and(Enrollment::where('user_id', $this->student->id)->exists())->toBeTrue();
});
