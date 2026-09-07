<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\getJson;

beforeEach(fn () => seedRegistry());

it('requires authentication', function (): void {
    getJson('/api/v1/account/profile')->assertStatus(401);
});

it('returns the caller profile including their own email', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create(['email' => 'me@example.com']);

    $this->actingAs($user)->getJson('/api/v1/account/profile')
        ->assertOk()
        ->assertJsonPath('data.email', 'me@example.com');
});

it('updates profile fields', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($user)->patchJson('/api/v1/account/profile', [
        'name' => 'Ada Lovelace',
        'headline' => 'Mathematician',
        'bio' => 'Wrote the first algorithm.',
        'timezone' => 'Asia/Dhaka',
        'locale' => 'bn',
    ])->assertOk()->assertJsonPath('data.name', 'Ada Lovelace');

    $fresh = $user->fresh();
    expect($fresh->headline)->toBe('Mathematician')
        ->and($fresh->timezone)->toBe('Asia/Dhaka')
        ->and($fresh->locale)->toBe('bn');
});

it('replaces social links wholesale', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($user)->patchJson('/api/v1/account/profile', [
        'social_links' => ['github' => 'https://github.com/ada', 'website' => 'https://ada.dev'],
    ])->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/account/profile', [
        'social_links' => ['github' => 'https://github.com/ada2'],
    ])->assertOk();

    $links = $user->fresh()->socialLinks->pluck('url', 'platform');
    expect($links)->toHaveCount(1)->and($links['github'])->toBe('https://github.com/ada2');
});

it('rejects an unsupported social platform', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();

    $response = $this->actingAs($user)->patchJson('/api/v1/account/profile', [
        'social_links' => ['myspace' => 'https://myspace.com/ada'],
    ])->assertStatus(422);

    expect($response)->toBeApiError('validation_failed');
});

it('rejects a non-url social link', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($user)->patchJson('/api/v1/account/profile', [
        'social_links' => ['github' => 'javascript:alert(1)'],
    ])->assertStatus(422);
});

it('rejects an invalid timezone', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($user)->patchJson('/api/v1/account/profile', ['timezone' => 'Mars/Olympus'])
        ->assertStatus(422);
});

it('will not let a user change fields the request does not expose', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create(['email' => 'fixed@example.com']);

    $this->actingAs($user)->patchJson('/api/v1/account/profile', [
        'name' => 'New Name',
        'email' => 'attacker@example.com',
        'status' => 'suspended',
    ])->assertOk();

    // Neither email nor status is in the Form Request, so neither is written.
    $fresh = $user->fresh();
    expect($fresh->email)->toBe('fixed@example.com')
        ->and($fresh->status->value)->toBe('active');
});

it('changes the password when the current one is correct', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($user)->postJson('/api/v1/account/password', [
        'current_password' => 'password',
        'password' => 'an entirely new secret',
        'password_confirmation' => 'an entirely new secret',
    ])->assertOk();

    expect(Hash::check('an entirely new secret', $user->fresh()->password))->toBeTrue();
});

it('refuses a password change without the correct current password', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/account/password', [
        'current_password' => 'wrong',
        'password' => 'an entirely new secret',
        'password_confirmation' => 'an entirely new secret',
    ])->assertStatus(422);

    expect(collect($response->json('error.details'))->pluck('field'))->toContain('current_password');
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('revokes API tokens when the password changes', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();
    $user->createToken('Phone');

    $this->actingAs($user)->postJson('/api/v1/account/password', [
        'current_password' => 'password',
        'password' => 'an entirely new secret',
        'password_confirmation' => 'an entirely new secret',
    ])->assertOk();

    expect($user->tokens()->count())->toBe(0);
});
