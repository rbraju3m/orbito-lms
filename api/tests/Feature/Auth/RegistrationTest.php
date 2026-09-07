<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\postJson;

uses()->beforeEach(fn () => $this->withHeaders(spaHeaders()));

beforeEach(function (): void {
    seedRegistry();
    Notification::fake();
});

it('registers a student and returns their resolved permissions', function (): void {
    $response = postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.name', 'Ada Lovelace')
        ->assertJsonPath('data.user.email', 'ada@example.com')
        ->assertJsonPath('data.roles', ['student'])
        ->assertJsonPath('data.must_verify_email', true)
        ->assertJsonPath('data.is_instructor', false);

    expect($response->json('data.permissions'))->toContain('review.create')
        ->and($response->json('data.permissions'))->not->toContain('course.create');

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect($user->hasRole(RoleKey::Student))->toBeTrue();
});

it('never returns the password hash', function (): void {
    $response = postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ]);

    expect(json_encode($response->json()))->not->toContain('$2y$');
});

it('sends a verification email', function (): void {
    postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ])->assertCreated();

    Notification::assertSentTo(
        User::where('email', 'ada@example.com')->firstOrFail(),
        VerifyEmailNotification::class,
    );
});

/*
 * Signing up as an instructor creates a PENDING application. It must not grant
 * the instructor role — approval does that, and only approval.
 */
it('does not grant the instructor role at signup', function (): void {
    $response = postJson('/api/v1/auth/register', [
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
        'wants_to_teach' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.roles', ['student'])
        ->assertJsonPath('data.is_instructor', false);

    $user = User::where('email', 'grace@example.com')->firstOrFail();

    expect($user->hasRole(RoleKey::Instructor))->toBeFalse()
        ->and($user->hasPermission('course.create'))->toBeFalse()
        ->and($user->instructorProfile?->status)->toBe(InstructorStatus::Pending);
});

it('issues a bearer token when a device name is supplied', function (): void {
    $response = postJson('/api/v1/auth/register', [
        'name' => 'Mobile User',
        'email' => 'mobile@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
        'device_name' => 'Pixel 7',
    ]);

    $response->assertCreated();
    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
});

it('does not issue a token for the cookie-based SPA flow', function (): void {
    $response = postJson('/api/v1/auth/register', [
        'name' => 'Web User',
        'email' => 'web@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ]);

    expect($response->json('data.token'))->toBeNull();
});

it('rejects a duplicate email', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = postJson('/api/v1/auth/register', [
        'name' => 'Someone',
        'email' => 'taken@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ])->assertStatus(422);

    expect($response)->toBeApiError('validation_failed');
    expect(collect($response->json('error.details'))->pluck('field'))->toContain('email');
});

it('rejects a mismatched password confirmation', function (): void {
    expect(postJson('/api/v1/auth/register', [
        'name' => 'Someone',
        'email' => 'someone@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'different',
    ])->assertStatus(422))->toBeApiError('validation_failed');
});

it('rejects an unsupported locale', function (): void {
    expect(postJson('/api/v1/auth/register', [
        'name' => 'Someone',
        'email' => 'someone@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
        'locale' => 'kl',
    ])->assertStatus(422))->toBeApiError('validation_failed');
});

it('normalises the email to lower case', function (): void {
    postJson('/api/v1/auth/register', [
        'name' => 'Mixed Case',
        'email' => 'Mixed.Case@Example.COM',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ])->assertCreated();

    expect(User::where('email', 'mixed.case@example.com')->exists())->toBeTrue();
});
