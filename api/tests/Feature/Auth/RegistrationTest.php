<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\VerifyEmailNotification;
use App\Domain\Platform\Enums\RegistrationMode;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\postJson;

uses()->beforeEach(fn () => $this->withHeaders(spaHeaders()));

beforeEach(function (): void {
    seedRegistry();
    Notification::fake();
});

it('registers a student and returns their resolved permissions', function (): void {
    $response = postJson('/api/v1/auth/register', [
        'academy' => 'test-academy',
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
        'academy' => 'test-academy',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ]);

    expect(json_encode($response->json()))->not->toContain('$2y$');
});

it('sends a verification email', function (): void {
    postJson('/api/v1/auth/register', [
        'academy' => 'test-academy',
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
        'academy' => 'test-academy',
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
        'academy' => 'test-academy',
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
        'academy' => 'test-academy',
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
        'academy' => 'test-academy',
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
        'academy' => 'test-academy',
        'name' => 'Someone',
        'email' => 'someone@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'different',
    ])->assertStatus(422))->toBeApiError('validation_failed');
});

it('rejects an unsupported locale', function (): void {
    expect(postJson('/api/v1/auth/register', [
        'academy' => 'test-academy',
        'name' => 'Someone',
        'email' => 'someone@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
        'locale' => 'kl',
    ])->assertStatus(422))->toBeApiError('validation_failed');
});

it('normalises the email to lower case', function (): void {
    postJson('/api/v1/auth/register', [
        'academy' => 'test-academy',
        'name' => 'Mixed Case',
        'email' => 'Mixed.Case@Example.COM',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ])->assertCreated();

    expect(User::where('email', 'mixed.case@example.com')->exists())->toBeTrue();
});

/*
 * WHICH academy an account belongs to.
 *
 * Tenancy resolves from the authenticated user and registration has none, so
 * until the academy became a parameter this route wrote a central user row
 * with a null `tenant_id` and assigned the Student role into whichever academy
 * happened to be open — the harness's shared one under test, none at all in a
 * real deployment. These are the tests that hole did not have.
 */
describe('the academy an account joins', function (): void {
    it('attaches the new account to the academy named in the request', function (): void {
        postJson('/api/v1/auth/register', [
            'academy' => 'test-academy',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery',
            'password_confirmation' => 'correct horse battery',
        ])->assertCreated();

        $user = User::where('email', 'ada@example.com')->firstOrFail();

        expect($user->tenant_id)->toBe(tenancy()->tenant->getTenantKey());
    });

    it('requires an academy', function (): void {
        $response = postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery',
            'password_confirmation' => 'correct horse battery',
        ])->assertStatus(422);

        expect($response)->toBeApiError('validation_failed')
            ->and(collect($response->json('error.details'))->pluck('field'))->toContain('academy');
    });

    /*
     * Told apart on purpose, unlike `tenant.path`'s uniform 404. The slug is
     * in a link the academy published, so its existence is not a secret, and
     * somebody following that link needs to know which of these it is.
     */
    it('says when no academy answers to that slug', function (): void {
        $response = postJson('/api/v1/auth/register', [
            'academy' => 'no-such-academy',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery',
            'password_confirmation' => 'correct horse battery',
        ])->assertStatus(403);

        expect($response)->toBeApiError('registration_not_open');
        expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
    });

    it('refuses when the academy has closed sign-ups', function (): void {
        $academy = Tenant::findOrFail(tenancy()->tenant->getTenantKey());
        $academy->registration_mode = RegistrationMode::Closed->value;
        $academy->save();

        $response = postJson('/api/v1/auth/register', [
            'academy' => 'test-academy',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery',
            'password_confirmation' => 'correct horse battery',
        ])->assertStatus(403);

        expect($response)->toBeApiError('registration_not_open')
            ->and($response->json('error.meta.registration_mode'))->toBe('closed');

        expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
    });

    /*
     * Declared and not built. An academy that selects it must find
     * registration CLOSED rather than silently falling back to open — which is
     * the failure the enum case exists to prevent.
     */
    it('refuses an invitation-only academy rather than falling back to open', function (): void {
        $academy = Tenant::findOrFail(tenancy()->tenant->getTenantKey());
        $academy->registration_mode = RegistrationMode::Invite->value;
        $academy->save();

        expect(postJson('/api/v1/auth/register', [
            'academy' => 'test-academy',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery',
            'password_confirmation' => 'correct horse battery',
        ])->assertStatus(403))->toBeApiError('registration_not_open');
    });

    it('defaults to open when the academy has never chosen', function (): void {
        $academy = Tenant::findOrFail(tenancy()->tenant->getTenantKey());

        expect($academy->registration_mode)->toBeNull()
            ->and($academy->registrationMode())->toBe(RegistrationMode::Open);

        postJson('/api/v1/auth/register', [
            'academy' => 'test-academy',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery',
            'password_confirmation' => 'correct horse battery',
        ])->assertCreated();
    });
});
