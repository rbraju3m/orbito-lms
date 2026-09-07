<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses()->beforeEach(fn () => $this->withHeaders(spaHeaders()));

beforeEach(function (): void {
    seedRegistry();
    RateLimiter::clear('');
});

it('signs a user in and returns their roles and permissions', function (): void {
    User::factory()->withRole(RoleKey::Instructor)->create(['email' => 'ins@example.com']);

    $response = postJson('/api/v1/auth/login', [
        'email' => 'ins@example.com',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.email', 'ins@example.com')
        ->assertJsonPath('data.must_verify_email', false);

    expect($response->json('data.roles'))->toContain('instructor')
        ->and($response->json('data.permissions'))->toContain('course.create');
});

it('accepts a mixed-case email', function (): void {
    User::factory()->create(['email' => 'case@example.com']);

    postJson('/api/v1/auth/login', [
        'email' => 'CASE@Example.com',
        'password' => 'password',
    ])->assertOk();
});

it('records the login timestamp', function (): void {
    $user = User::factory()->create(['email' => 'stamp@example.com', 'last_login_at' => null]);

    postJson('/api/v1/auth/login', ['email' => 'stamp@example.com', 'password' => 'password'])
        ->assertOk();

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

/*
 * The failure message must be identical whether or not the address exists,
 * otherwise this endpoint becomes an account-enumeration oracle.
 */
it('gives the same answer for a wrong password and an unknown address', function (): void {
    User::factory()->create(['email' => 'real@example.com']);

    $wrongPassword = postJson('/api/v1/auth/login', [
        'email' => 'real@example.com',
        'password' => 'not-the-password',
    ])->assertStatus(422);

    $unknownUser = postJson('/api/v1/auth/login', [
        'email' => 'ghost@example.com',
        'password' => 'not-the-password',
    ])->assertStatus(422);

    expect($wrongPassword)->toBeApiError('invalid_credentials')
        ->and($unknownUser)->toBeApiError('invalid_credentials')
        ->and($wrongPassword->json('error.message'))->toBe($unknownUser->json('error.message'));
});

it('refuses a suspended account with a distinct reason', function (): void {
    User::factory()->suspended()->create(['email' => 'banned@example.com']);

    expect(postJson('/api/v1/auth/login', [
        'email' => 'banned@example.com',
        'password' => 'password',
    ])->assertStatus(403))->toBeApiError('account_suspended');
});

it('issues a bearer token that authenticates subsequent requests', function (): void {
    User::factory()->withRole(RoleKey::Student)->create(['email' => 'mobile@example.com']);

    $token = postJson('/api/v1/auth/login', [
        'email' => 'mobile@example.com',
        'password' => 'password',
        'device_name' => 'Pixel 7',
    ])->assertOk()->json('data.token');

    expect($token)->toBeString()->not->toBeEmpty();

    getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('data.user.email', 'mobile@example.com');
});

it('requires a device name when the caller is not the first-party SPA', function (): void {
    User::factory()->create(['email' => 'api@example.com']);

    // flushHeaders, not withHeaders([]) — the latter merges, so the SPA Origin
    // set for this file would survive and the request would still be stateful.
    $response = $this->flushHeaders()->postJson('/api/v1/auth/login', [
        'email' => 'api@example.com',
        'password' => 'password',
    ]);

    // Without a stateful origin there is no session to hold a cookie, so the
    // caller must name a device to receive a token instead.
    $response->assertStatus(422);
    expect(collect($response->json('error.details'))->pluck('field'))->toContain('device_name');
});

it('throttles repeated failures', function (): void {
    User::factory()->create(['email' => 'target@example.com']);

    $limit = (int) config('orbito.rate_limits.auth');

    for ($i = 0; $i < $limit; $i++) {
        postJson('/api/v1/auth/login', ['email' => 'target@example.com', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    expect(postJson('/api/v1/auth/login', [
        'email' => 'target@example.com',
        'password' => 'wrong',
    ])->assertStatus(429))->toBeApiError('rate_limited');
});

it('rejects a missing password with a validation error', function (): void {
    expect(postJson('/api/v1/auth/login', ['email' => 'x@example.com'])->assertStatus(422))
        ->toBeApiError('validation_failed');
});
