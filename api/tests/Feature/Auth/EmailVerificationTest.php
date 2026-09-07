<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\postJson;

beforeEach(fn () => seedRegistry());

/** Rebuilds the query string the emailed link carries. */
function verificationQuery(User $user, ?string $hash = null): string
{
    $signed = URL::temporarySignedRoute(
        'auth.email.verify',
        now()->addMinutes(60),
        ['id' => $user->getKey(), 'hash' => $hash ?? sha1($user->getEmailForVerification())],
        absolute: false,
    );

    return (string) parse_url($signed, PHP_URL_QUERY);
}

it('verifies an email from a signed link', function (): void {
    Event::fake([Verified::class]);
    $user = User::factory()->unverified()->create();

    postJson('/api/v1/auth/email/verify?'.verificationQuery($user))
        ->assertOk()
        ->assertJsonPath('data.verified', true);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
});

it('rejects a link whose signature has been tampered with', function (): void {
    $user = User::factory()->unverified()->create();
    $query = verificationQuery($user);
    $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('a', 64), $query);

    postJson('/api/v1/auth/email/verify?'.$tampered)->assertStatus(403);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects an expired link', function (): void {
    $user = User::factory()->unverified()->create();

    $signed = URL::temporarySignedRoute(
        'auth.email.verify',
        now()->subMinute(),
        ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        absolute: false,
    );

    postJson('/api/v1/auth/email/verify?'.parse_url($signed, PHP_URL_QUERY))->assertStatus(403);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

/*
 * A correctly signed link is only valid for the address it was minted for. This
 * is the check that stops a valid signature for user A verifying user B.
 */
it('rejects a validly signed link whose hash does not match the address', function (): void {
    $victim = User::factory()->unverified()->create(['email' => 'victim@example.com']);

    $response = postJson(
        '/api/v1/auth/email/verify?'.verificationQuery($victim, sha1('someone-else@example.com'))
    )->assertStatus(422);

    expect($response)->toBeApiError('invalid_verification_link')
        ->and($victim->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('reports a conflict when the address is already verified', function (): void {
    $user = User::factory()->create(); // verified by default

    expect(postJson('/api/v1/auth/email/verify?'.verificationQuery($user))->assertStatus(409))
        ->toBeApiError('email_already_verified');
});

it('resends the verification email to an unverified caller', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->postJson('/api/v1/auth/email/resend')->assertAccepted();

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('refuses to resend to a verified caller', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    expect($this->actingAs($user)->postJson('/api/v1/auth/email/resend')->assertStatus(409))
        ->toBeApiError('email_already_verified');

    Notification::assertNothingSent();
});

it('requires authentication to resend', function (): void {
    postJson('/api/v1/auth/email/resend')->assertStatus(401);
});
