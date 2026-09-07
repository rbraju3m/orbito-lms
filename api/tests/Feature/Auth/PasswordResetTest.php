<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

use function Pest\Laravel\postJson;

uses()->beforeEach(fn () => $this->withHeaders(spaHeaders()));

beforeEach(function (): void {
    seedRegistry();
    Notification::fake();
});

it('sends a reset link to a known address', function (): void {
    $user = User::factory()->create(['email' => 'known@example.com']);

    postJson('/api/v1/auth/forgot-password', ['email' => 'known@example.com'])->assertAccepted();

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

/*
 * The response for an unknown address must be indistinguishable from a known
 * one, or this endpoint enumerates accounts.
 */
it('answers identically for an unknown address', function (): void {
    $known = postJson('/api/v1/auth/forgot-password', ['email' => 'known@example.com']);
    $unknown = postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

    expect($known->status())->toBe($unknown->status())
        ->and($known->json('data.message'))->toBe($unknown->json('data.message'));

    Notification::assertNothingSent();
});

it('resets the password with a valid token', function (): void {
    $user = User::factory()->create(['email' => 'reset@example.com']);
    $token = Password::createToken($user);

    postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'reset@example.com',
        'password' => 'a brand new secret',
        'password_confirmation' => 'a brand new secret',
    ])->assertOk()->assertJsonPath('data.reset', true);

    expect(Hash::check('a brand new secret', $user->fresh()->password))->toBeTrue();
});

it('revokes every API token when the password is reset', function (): void {
    $user = User::factory()->create(['email' => 'reset@example.com']);
    $user->createToken('Phone');
    $user->createToken('Tablet');
    expect($user->tokens()->count())->toBe(2);

    postJson('/api/v1/auth/reset-password', [
        'token' => Password::createToken($user),
        'email' => 'reset@example.com',
        'password' => 'a brand new secret',
        'password_confirmation' => 'a brand new secret',
    ])->assertOk();

    // A reset is how someone recovers a compromised account; leaving old
    // tokens alive would defeat the point.
    expect($user->tokens()->count())->toBe(0);
});

it('rejects an invalid token', function (): void {
    User::factory()->create(['email' => 'reset@example.com']);

    expect(postJson('/api/v1/auth/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'reset@example.com',
        'password' => 'a brand new secret',
        'password_confirmation' => 'a brand new secret',
    ])->assertStatus(422))->toBeApiError('validation_failed');
});

it('rejects a token issued for a different address', function (): void {
    $a = User::factory()->create(['email' => 'a@example.com']);
    User::factory()->create(['email' => 'b@example.com']);

    postJson('/api/v1/auth/reset-password', [
        'token' => Password::createToken($a),
        'email' => 'b@example.com',
        'password' => 'a brand new secret',
        'password_confirmation' => 'a brand new secret',
    ])->assertStatus(422);
});

it('throttles reset requests', function (): void {
    $limit = (int) config('orbito.rate_limits.auth');

    for ($i = 0; $i < $limit; $i++) {
        postJson('/api/v1/auth/forgot-password', ['email' => 'known@example.com'])->assertAccepted();
    }

    expect(postJson('/api/v1/auth/forgot-password', ['email' => 'known@example.com'])->assertStatus(429))
        ->toBeApiError('rate_limited');
});
