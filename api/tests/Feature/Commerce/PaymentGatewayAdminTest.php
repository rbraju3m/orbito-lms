<?php

declare(strict_types=1);

use App\Domain\Commerce\Data\WebhookEvent;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

/*
 * An academy connecting its own gateway (ADR-13). The platform is not the
 * merchant, so these credentials live in the academy's schema and the
 * capability belongs to an academy admin.
 */

beforeEach(function (): void {
    seedRegistry();
    $this->admin = User::factory()->withRole(RoleKey::Admin)->create();
});

it('lists every supported gateway, connected or not', function (): void {
    // An admin cannot connect Stripe from a list that only shows what is
    // already connected.
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/payment-gateways')
        ->assertOk();

    $gateways = collect($response->json('data'))->pluck('gateway');

    expect($gateways)->toContain('stripe')
        ->and($response->json('data.0.is_connected'))->toBeFalse();
});

it('connects a gateway and never reads the credentials back', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/payment-gateways/fake', [
            'credentials' => ['key' => 'sk_live_do_not_leak_me'],
            'webhook_secret' => 'whsec_do_not_leak_me',
            'is_active' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_connected', true)
        ->assertJsonPath('data.has_webhook_secret', true)
        ->assertJsonPath('data.is_active', true)
        // The resource has no branch that could emit these at all.
        ->assertJsonMissingPath('data.credentials')
        ->assertJsonMissingPath('data.webhook_secret');

    // Belt and braces: not anywhere in the body under any key.
    $body = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/payment-gateways')
        ->getContent();

    expect($body)->not->toContain('sk_live_do_not_leak_me')
        ->and($body)->not->toContain('whsec_do_not_leak_me');
});

it('stores the credentials encrypted', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/payment-gateways/fake', [
            'credentials' => ['key' => 'sk_live_secret_value'],
            'webhook_secret' => 'whsec_secret_value',
        ])
        ->assertOk();

    $raw = DB::table('payment_gateway_accounts')->where('gateway', 'fake')->first();

    expect($raw->credentials)->not->toContain('sk_live_secret_value')
        ->and($raw->webhook_secret)->not->toContain('whsec_secret_value');

    // And still usable.
    expect(PaymentGatewayAccount::where('gateway', Gateway::Fake)->firstOrFail()->credentials['key'])
        ->toBe('sk_live_secret_value');
});

it('keeps the stored secret when a partial update omits it', function (): void {
    $this->actingAs($this->admin)->putJson('/api/v1/admin/payment-gateways/fake', [
        'credentials' => ['key' => 'sk_test_keep_me'],
        'webhook_secret' => 'whsec_keep_me',
        'is_active' => true,
    ])->assertOk();

    // Toggling test mode must not silently disconnect the gateway.
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/payment-gateways/fake', ['is_test_mode' => false])
        ->assertOk()
        ->assertJsonPath('data.is_connected', true)
        ->assertJsonPath('data.has_webhook_secret', true)
        ->assertJsonPath('data.is_test_mode', false);

    expect(PaymentGatewayAccount::where('gateway', Gateway::Fake)->firstOrFail()->credentials['key'])
        ->toBe('sk_test_keep_me');
});

it('refuses to activate a gateway that has no credentials', function (): void {
    // Otherwise the failure surfaces at a learner's checkout instead of here.
    expect($this->actingAs($this->admin)
        ->putJson('/api/v1/admin/payment-gateways/fake', ['is_active' => true])
        ->assertStatus(503))->toBeApiError('gateway_unavailable');
});

it('disconnects by deleting the row, not by deactivating it', function (): void {
    $this->actingAs($this->admin)->putJson('/api/v1/admin/payment-gateways/fake', [
        'credentials' => ['key' => 'sk_test_bye'],
    ])->assertOk();

    $this->actingAs($this->admin)
        ->deleteJson('/api/v1/admin/payment-gateways/fake')
        ->assertNoContent();

    // Keeping encrypted credentials for an account the academy has abandoned
    // is a secret kept for no reason.
    expect(PaymentGatewayAccount::count())->toBe(0);
});

it('404s for a gateway name that is not a gateway', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/payment-gateways/not_a_gateway', ['is_test_mode' => true])
        ->assertNotFound();
});

it('rejects a credential value that is not a string', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/payment-gateways/fake', [
            'credentials' => ['key' => ['nested' => 'array']],
        ])
        ->assertStatus(422);
});

/* ------------------------------------------------------------ authorization */

it('forbids a learner from reading or writing gateway configuration', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($student)->getJson('/api/v1/admin/payment-gateways')->assertForbidden();
    $this->actingAs($student)
        ->putJson('/api/v1/admin/payment-gateways/fake', ['is_test_mode' => true])
        ->assertForbidden();
    $this->actingAs($student)
        ->deleteJson('/api/v1/admin/payment-gateways/fake')
        ->assertForbidden();
});

it('forbids staff, who run orders but move no money', function (): void {
    // Staff hold order.view.any and no gateway.manage — the split is the point.
    $staff = User::factory()->withRole(RoleKey::Staff)->create();

    $this->actingAs($staff)->getJson('/api/v1/admin/payment-gateways')->assertForbidden();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/admin/payment-gateways')->assertUnauthorized();
});

/* ------------------------------------------------------------ webhook setup */

it('tells the admin where the provider must send webhooks, and which events', function (): void {
    // Fake connected, Stripe not: both shapes of the row carry the setup.
    $this->actingAs($this->admin)->putJson('/api/v1/admin/payment-gateways/fake', [
        'credentials' => ['key' => 'sk_test_webhook_setup'],
    ])->assertOk();

    $rows = collect($this->actingAs($this->admin)
        ->getJson('/api/v1/admin/payment-gateways')
        ->assertOk()
        ->json('data'))->keyBy('gateway');

    // The academy id in this path is shown nowhere else in the product, and
    // Stripe's endpoint setup cannot be finished without it.
    $academy = tenant()->getTenantKey();

    expect($rows['stripe']['webhook_url'])->toEndWith("/api/v1/webhooks/payments/stripe/{$academy}")
        ->and($rows['stripe']['webhook_url'])->toBe(route('webhooks.payments', ['gateway' => 'stripe', 'tenant' => $academy]))
        ->and($rows['stripe']['webhook_events'])->toBe([
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'checkout.session.expired',
            'refund.created',
            'refund.updated',
            'refund.failed',
        ])
        ->and($rows['fake']['is_connected'])->toBeTrue()
        ->and($rows['fake']['webhook_url'])->toEndWith("/api/v1/webhooks/payments/fake/{$academy}");
});

/*
 * The screen and the handler read one list. An event named here that
 * HandleWebhook does not act on is an academy told to send something that is
 * then recorded and ignored — a payment that never grants access.
 */
it('names only events the webhook handler acts on', function (Gateway $gateway): void {
    // A gateway that cannot be connected has no endpoint to set up. One that
    // can must say what to send, or its setup block tells the academy nothing.
    expect($gateway->webhookEvents() === [])->toBe(! $gateway->isAvailable());

    foreach ($gateway->webhookEvents() as $type) {
        $event = new WebhookEvent(id: 'evt_check', type: $type, externalPaymentId: null, amountMinor: null, currency: null, payload: []);

        expect($event->isSuccess() || $event->isFailure() || $event->isRefund())
            ->toBeTrue("{$gateway->value} lists {$type}, which HandleWebhook ignores");
    }
})->with(Gateway::cases());
