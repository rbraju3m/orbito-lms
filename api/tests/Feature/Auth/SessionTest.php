<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses()->beforeEach(fn () => $this->withHeaders(spaHeaders()));

beforeEach(fn () => seedRegistry());

it('requires authentication for the me endpoint', function (): void {
    expect(getJson('/api/v1/auth/me')->assertStatus(401))->toBeApiError('unauthenticated');
});

it('returns the caller with roles and permissions', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    $response = $this->actingAs($admin)->getJson('/api/v1/auth/me')->assertOk();

    expect($response->json('data.roles'))->toContain('admin')
        ->and($response->json('data.permissions'))->toContain('user.delete')
        ->and($response->json('data.permissions'))->not->toContain('role.create');
});

it('updates last seen when the caller identifies itself', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create(['last_seen_at' => null]);

    $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk();

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

it('revokes only the calling device token on logout', function (): void {
    seedRegistry();
    $user = User::factory()->withRole(RoleKey::Student)->create(['email' => 'multi@example.com']);

    $phone = postJson('/api/v1/auth/login', [
        'email' => 'multi@example.com', 'password' => 'password', 'device_name' => 'Phone',
    ])->json('data.token');

    $tablet = postJson('/api/v1/auth/login', [
        'email' => 'multi@example.com', 'password' => 'password', 'device_name' => 'Tablet',
    ])->json('data.token');

    postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$phone}"])->assertNoContent();

    // Signing out of one device must not sign the user out everywhere.
    getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$phone}"])->assertStatus(401);
    getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$tablet}"])->assertOk();
});

it('does not expose another user private fields', function (): void {
    $viewer = User::factory()->withRole(RoleKey::Student)->create();
    $other = User::factory()->withRole(RoleKey::Student)->create(['email' => 'private@example.com']);

    // A plain student may not read the admin user endpoint at all.
    $this->actingAs($viewer)
        ->getJson("/api/v1/admin/users/{$other->uuid}")
        ->assertStatus(403);
});
