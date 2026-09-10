<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Webhook\Enums\DeliveryStatus;
use App\Domain\Webhook\Models\WebhookDelivery;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Domain\Webhook\Support\HostResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeHostResolver;

/*
 * The admin surface for outbound webhooks (ADR-12). `webhook.manage` is held
 * by the academy's Super Admin alone: an endpoint receives learners' names and
 * email addresses, so deciding where one points is not an everyday admin task.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->dns = new FakeHostResolver;
    app()->instance(HostResolver::class, $this->dns);
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $this->owner = userWithRole(RoleKey::SuperAdmin);
});

function createEndpoint(array $overrides = []): TestResponse
{
    return test()->actingAs(test()->owner)->postJson('/api/v1/admin/webhooks', $overrides + [
        'url' => 'https://hooks.example.com/orbito',
        'description' => 'CRM',
        'events' => ['enrollment.created', 'course.completed'],
    ]);
}

/* ------------------------------------------------------------ the secret */

it('creates an endpoint and shows its secret exactly once', function (): void {
    $created = createEndpoint()->assertCreated()->json('data');

    expect($created['secret'])->toStartWith('whsec_')
        ->and($created['events'])->toBe(['enrollment.created', 'course.completed'])
        ->and($created['is_active'])->toBeTrue();

    // Never read back — not on the detail, not in the list.
    $this->actingAs($this->owner)->getJson("/api/v1/admin/webhooks/{$created['id']}")
        ->assertOk()->assertDontSee($created['secret']);
    $this->actingAs($this->owner)->getJson('/api/v1/admin/webhooks')
        ->assertOk()->assertDontSee($created['secret']);

    // And not stored as itself.
    expect(DB::table('webhook_endpoints')->value('secret'))->not->toBe($created['secret'])
        ->and(WebhookEndpoint::firstOrFail()->secret)->toBe($created['secret']);
});

it('rotates the secret and shows the new one once', function (): void {
    $created = createEndpoint()->assertCreated()->json('data');

    $rotated = $this->actingAs($this->owner)
        ->postJson("/api/v1/admin/webhooks/{$created['id']}/rotate-secret")
        ->assertOk()->json('data.secret');

    expect($rotated)->toStartWith('whsec_')->not->toBe($created['secret'])
        ->and(WebhookEndpoint::firstOrFail()->secret)->toBe($rotated);
});

/* ---------------------------------------------------------------- listing */

it('lists endpoints with every subscribable topic for the picker', function (): void {
    createEndpoint()->assertCreated();

    $body = $this->actingAs($this->owner)->getJson('/api/v1/admin/webhooks')->assertOk()->json();

    $topics = array_column($body['meta']['topics'], 'value');

    expect($body['data'])->toHaveCount(1)
        ->and($topics)->toContain('enrollment.created', 'payment.captured')
        // Sent on request only; nothing subscribes to it.
        ->and($topics)->not->toContain('ping');
});

/* ------------------------------------------------------------ who may */

it('refuses anybody without webhook.manage', function (RoleKey $role): void {
    $actor = userWithRole($role);
    $endpoint = WebhookEndpoint::factory()->create();

    $this->actingAs($actor)->getJson('/api/v1/admin/webhooks')->assertForbidden();
    $this->actingAs($actor)->postJson('/api/v1/admin/webhooks', [
        'url' => 'https://hooks.example.com/x', 'events' => ['enrollment.created'],
    ])->assertForbidden();
    $this->actingAs($actor)->getJson("/api/v1/admin/webhooks/{$endpoint->uuid}/deliveries")->assertForbidden();
    $this->actingAs($actor)->deleteJson("/api/v1/admin/webhooks/{$endpoint->uuid}")->assertForbidden();

    expect(WebhookEndpoint::count())->toBe(1);
})->with([RoleKey::Admin, RoleKey::Instructor, RoleKey::Student]);

/* ------------------------------------------------------------ validation */

it('validates what it is given', function (array $payload): void {
    expect(createEndpoint($payload)->assertStatus(422))->toBeApiError('validation_failed');

    expect(WebhookEndpoint::count())->toBe(0);
})->with([
    'no topics' => [['events' => []]],
    'an unknown topic' => [['events' => ['enrollment.teleported']]],
    'ping is not subscribable' => [['events' => ['ping']]],
    'not a URL' => [['url' => 'not a url']],
]);

it('refuses an internal address at the moment it is saved', function (): void {
    $this->dns->point('crm.internal.example.com', '10.20.30.40');

    expect(createEndpoint(['url' => 'https://crm.internal.example.com/in'])->assertStatus(422))
        ->toBeApiError('webhook_target_refused');

    expect(WebhookEndpoint::count())->toBe(0);
});

it('refuses plain http', function (): void {
    expect(createEndpoint(['url' => 'http://hooks.example.com/in'])->assertStatus(422))
        ->toBeApiError('webhook_target_refused');
});

/* ---------------------------------------------------------------- update */

it('changes topics, and switching back on clears the failure count', function (): void {
    $endpoint = WebhookEndpoint::factory()->create([
        'is_active' => false,
        'consecutive_failures' => 5,
        'disabled_at' => now(),
        'disabled_reason' => 'Switched off automatically.',
    ]);

    $body = $this->actingAs($this->owner)->patchJson("/api/v1/admin/webhooks/{$endpoint->uuid}", [
        'events' => ['payment.captured'],
        'is_active' => true,
    ])->assertOk()->json('data');

    expect($body['events'])->toBe(['payment.captured'])
        ->and($body['is_active'])->toBeTrue()
        ->and($body['consecutive_failures'])->toBe(0)
        ->and($body['disabled_reason'])->toBeNull();
});

it('says who switched an endpoint off', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/admin/webhooks/{$endpoint->uuid}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.disabled_reason', 'Switched off by an administrator.');
});

/* ------------------------------------------------------ test & redeliver */

it('sends a signed test event', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();

    $this->actingAs($this->owner)->postJson("/api/v1/admin/webhooks/{$endpoint->uuid}/test")
        ->assertStatus(202)
        ->assertJsonPath('data.topic', 'ping');

    Http::assertSent(fn ($request) => $request['type'] === 'ping' && $request->hasHeader('Orbito-Signature'));
    expect(WebhookDelivery::firstOrFail()->status)->toBe(DeliveryStatus::Succeeded);
});

it('will not send to an endpoint that is switched off', function (): void {
    $endpoint = WebhookEndpoint::factory()->disabled()->create();

    expect($this->actingAs($this->owner)->postJson("/api/v1/admin/webhooks/{$endpoint->uuid}/test")
        ->assertStatus(409))->toBeApiError('webhook_endpoint_disabled');

    Http::assertNothingSent();
});

it('redelivers an event as a new delivery with the same event id', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();
    $original = WebhookDelivery::factory()->failed()->create(['endpoint_id' => $endpoint->id]);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/admin/webhooks/{$endpoint->uuid}/deliveries/{$original->uuid}/redeliver")
        ->assertStatus(202)
        ->assertJsonPath('data.event_id', $original->event_id);

    // The first try stays in the log as it happened.
    expect(WebhookDelivery::count())->toBe(2)
        ->and($original->fresh()->status)->toBe(DeliveryStatus::Failed);
    Http::assertSent(fn ($request) => $request->body() === $original->body);
});

/* Both bind globally by uuid; membership is checked in the controller. */
it('will not redeliver another endpoint\'s delivery through this one', function (): void {
    $mine = WebhookEndpoint::factory()->create();
    $theirs = WebhookDelivery::factory()->failed()->create();

    $this->actingAs($this->owner)
        ->postJson("/api/v1/admin/webhooks/{$mine->uuid}/deliveries/{$theirs->uuid}/redeliver")
        ->assertNotFound();

    expect(WebhookDelivery::count())->toBe(1);
});

it('lists an endpoint\'s deliveries, newest first, with what was sent', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();
    WebhookDelivery::factory()->count(2)->create(['endpoint_id' => $endpoint->id]);
    WebhookDelivery::factory()->create(); // another endpoint's

    $rows = $this->actingAs($this->owner)
        ->getJson("/api/v1/admin/webhooks/{$endpoint->uuid}/deliveries")
        ->assertOk()->json('data');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['payload']['type'])->toBe('ping')
        ->and($rows[0]['max_attempts'])->toBe(8);
});

/* -------------------------------------------------------------- delete */

it('deletes an endpoint and whatever was still queued for it', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();
    WebhookDelivery::factory()->create(['endpoint_id' => $endpoint->id]);

    $this->actingAs($this->owner)->deleteJson("/api/v1/admin/webhooks/{$endpoint->uuid}")->assertNoContent();

    expect(WebhookEndpoint::count())->toBe(0)
        ->and(WebhookDelivery::count())->toBe(0);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/admin/webhooks')->assertUnauthorized();
    expect(User::count())->toBeGreaterThan(0);
});
