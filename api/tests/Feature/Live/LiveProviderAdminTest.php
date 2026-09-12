<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Enums\LiveProvider;
use App\Domain\Live\Models\LiveProviderAccount;
use App\Domain\Live\Models\LiveSession;
use Illuminate\Support\Facades\DB;

/*
 * An academy connecting its own meeting provider. The platform holds no Zoom
 * or Google account on anybody's behalf — the ADR-13 decision payment
 * gateways made — so the row is in the academy's schema and the capability
 * belongs to an academy admin.
 */

beforeEach(function (): void {
    seedRegistry();
    $this->admin = User::factory()->withRole(RoleKey::Admin)->create();
});

it('lists every provider, connected or not', function (): void {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/live-providers')
        ->assertOk();

    $rows = collect($response->json('data'))->keyBy('provider');

    expect($rows->keys())->toContain('manual', 'zoom', 'google_meet')
        ->and($rows['zoom']['is_connected'])->toBeFalse()
        // Manual is listed and needs nothing — a screen that hid it would
        // suggest live sessions require an integration when they do not.
        ->and($rows['manual']['needs_account'])->toBeFalse()
        ->and($rows['manual']['fields'])->toBe([]);
});

it('declares the fields each provider needs, so the form cannot drift from the validator', function (): void {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/live-providers')
        ->assertOk();

    $zoom = collect($response->json('data'))->firstWhere('provider', 'zoom');
    $keys = collect($zoom['fields'])->pluck('key');

    expect($keys)->toContain('account_id', 'client_id', 'client_secret')
        ->and(collect($zoom['fields'])->firstWhere('key', 'client_secret')['secret'])->toBeTrue()
        // Optional, so an academy with one Zoom host never has to invent one.
        ->and(collect($zoom['fields'])->firstWhere('key', 'host_email')['required'])->toBeFalse();

    $google = collect($response->json('data'))->firstWhere('provider', 'google_meet');

    // A service account, NOT an access token: Google's tokens live an hour,
    // so a box asking for one would connect an academy until lunchtime.
    expect(collect($google['fields'])->pluck('key'))
        ->toContain('client_email', 'private_key')
        ->not->toContain('access_token');
});

it('connects a provider and never reads the credentials back', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/live-providers/zoom', [
            'credentials' => [
                'account_id' => 'acct-1',
                'client_id' => 'client-1',
                'client_secret' => 'zoom_do_not_leak_me',
            ],
            'is_active' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_connected', true)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonMissingPath('data.credentials');

    $body = $this->actingAs($this->admin)->getJson('/api/v1/admin/live-providers')->getContent();

    expect($body)->not->toContain('zoom_do_not_leak_me');
});

it('stores the credentials encrypted', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/live-providers/zoom', [
            'credentials' => [
                'account_id' => 'acct-1',
                'client_id' => 'client-1',
                'client_secret' => 'zoom_do_not_leak_me',
            ],
        ])
        ->assertOk();

    $stored = DB::table('live_provider_accounts')->where('provider', 'zoom')->value('credentials');

    expect($stored)->toBeString()->not->toContain('zoom_do_not_leak_me');
});

it('keeps what a partial update leaves out', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/live-providers/zoom', [
            'credentials' => [
                'account_id' => 'acct-1',
                'client_id' => 'client-1',
                'client_secret' => 'the-secret',
            ],
        ])
        ->assertOk();

    // Fixing a typo'd account id must not mean re-entering a secret Zoom
    // only ever shows once.
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/live-providers/zoom', [
            'credentials' => ['account_id' => 'acct-2'],
        ])
        ->assertOk();

    $account = LiveProviderAccount::query()->where('provider', LiveProvider::Zoom)->sole();

    expect($account->credentials)->toMatchArray([
        'account_id' => 'acct-2',
        'client_secret' => 'the-secret',
    ]);
});

it('refuses credentials that would not be enough to schedule with, and says which', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/live-providers/zoom', [
            'credentials' => ['account_id' => 'acct-1'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'live_provider_credentials_incomplete')
        // A dead end on a four-box form otherwise.
        ->assertJsonPath('error.meta.missing', ['client_id', 'client_secret']);

    expect(LiveProviderAccount::query()->count())->toBe(0);
});

it('refuses a credential key the provider does not have', function (): void {
    // Stored in an encrypted blob nothing would ever read, and impossible to
    // spot afterwards.
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/live-providers/zoom', [
            'credentials' => ['account_id' => 'a', 'client_id' => 'b', 'client_secret' => 'c', 'oops' => 'd'],
        ])
        ->assertStatus(422);
});

it('refuses to connect the provider that connects to nothing', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/live-providers/manual', ['is_active' => true])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'live_provider_needs_no_account');
});

it('404s an unknown provider', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/live-providers/teams', ['is_active' => false])
        ->assertNotFound();
});

it('refuses an instructor, who schedules classes but does not hold the academy keys', function (): void {
    // The § Authorization trap: an instructor holds `live.manage.own`
    // globally, and that must not reach the academy's credentials.
    $instructor = User::factory()->instructor()->create();

    $this->actingAs($instructor)->getJson('/api/v1/admin/live-providers')->assertForbidden();

    $this->actingAs($instructor)
        ->putJson('/api/v1/admin/live-providers/zoom', [
            'credentials' => ['account_id' => 'a', 'client_id' => 'b', 'client_secret' => 'c'],
        ])
        ->assertForbidden();
});

it('disconnects, and reports what a disconnect would strand', function (): void {
    LiveProviderAccount::create([
        'provider' => LiveProvider::Zoom,
        'credentials' => ['account_id' => 'a', 'client_id' => 'b', 'client_secret' => 'c'],
        'is_active' => true,
    ]);

    LiveSession::factory()->create([
        'provider' => LiveProvider::Zoom,
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addHour(),
    ]);

    $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/live-providers')->assertOk();

    expect(collect($response->json('data'))->firstWhere('provider', 'zoom')['upcoming_sessions'])
        ->toBe(1);

    $this->actingAs($this->admin)
        ->deleteJson('/api/v1/admin/live-providers/zoom')
        ->assertNoContent();

    // Deleted, not deactivated: credentials kept for an account the academy
    // has finished with are a secret held for no reason.
    expect(LiveProviderAccount::query()->count())->toBe(0);
});

it('agrees with the studio picker about what is connected', function (): void {
    // The one rule, asked twice: the scheduling form offers what
    // LiveProviderFactory would accept, and this screen is what changes it.
    $course = Course::factory()->published()->create();

    $available = fn (): array => collect(
        $this->actingAs($this->admin)
            ->getJson("/api/v1/courses/{$course->uuid}/live-sessions")
            ->json('meta.providers'),
    )->firstWhere('value', 'zoom');

    expect($available()['available'])->toBeFalse();

    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/live-providers/zoom', [
            'credentials' => ['account_id' => 'a', 'client_id' => 'b', 'client_secret' => 'c'],
            'is_active' => true,
        ])
        ->assertOk();

    expect($available()['available'])->toBeTrue();
});
