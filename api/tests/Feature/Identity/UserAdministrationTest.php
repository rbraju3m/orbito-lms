<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;

beforeEach(fn () => seedRegistry());

it('lists users for someone who may view them', function (): void {
    User::factory()->withRole(RoleKey::Student)->count(3)->create();
    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    $this->actingAs($admin)->getJson('/api/v1/admin/users')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page'], 'links']);
});

it('caps the page size a client may request', function (): void {
    User::factory()->withRole(RoleKey::Student)->count(3)->create();
    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    $response = $this->actingAs($admin)->getJson('/api/v1/admin/users?per_page=5000')->assertOk();

    expect($response->json('meta.per_page'))
        ->toBeLessThanOrEqual((int) config('orbito.pagination.max_per_page'));
});

it('denies the user list to a student', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();

    expect($this->actingAs($student)->getJson('/api/v1/admin/users')->assertStatus(403))
        ->toBeApiError('forbidden');
});

it('denies the user list to an instructor', function (): void {
    $instructor = User::factory()->instructor()->create();

    $this->actingAs($instructor)->getJson('/api/v1/admin/users')->assertStatus(403);
});

it('suspends a user and revokes their tokens', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $target = User::factory()->withRole(RoleKey::Student)->create();
    $target->createToken('Phone');

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/users/{$target->uuid}/suspension", ['suspended' => true])
        ->assertOk();

    $fresh = $target->fresh();
    expect($fresh->status)->toBe(UserStatus::Suspended)
        ->and($fresh->tokens()->count())->toBe(0);
});

it('reinstates a suspended user', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $target = User::factory()->suspended()->withRole(RoleKey::Student)->create();

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/users/{$target->uuid}/suspension", ['suspended' => false])
        ->assertOk();

    expect($target->fresh()->status)->toBe(UserStatus::Active);
});

it('refuses to suspend a super admin', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $super = User::factory()->withRole(RoleKey::SuperAdmin)->create();

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/users/{$super->uuid}/suspension", ['suspended' => true])
        ->assertStatus(403);

    expect($super->fresh()->status)->toBe(UserStatus::Active);
});

it('refuses to let a user suspend themselves', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/users/{$admin->uuid}/suspension", ['suspended' => true])
        ->assertStatus(403);
});

it('addresses users by uuid, never by the auto-increment id', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $target = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($admin)->getJson("/api/v1/admin/users/{$target->id}")->assertNotFound();
    $this->actingAs($admin)->getJson("/api/v1/admin/users/{$target->uuid}")->assertOk();
});
