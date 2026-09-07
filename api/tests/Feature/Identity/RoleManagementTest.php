<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(fn () => seedRegistry());

it('lists roles with their permissions for someone who may view them', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    $response = $this->actingAs($admin)->getJson('/api/v1/admin/roles')->assertOk();

    expect(collect($response->json('data'))->pluck('key'))
        ->toContain('super_admin', 'admin', 'instructor', 'teaching_assistant');
});

it('denies the role list to a student', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();

    expect($this->actingAs($student)->getJson('/api/v1/admin/roles')->assertStatus(403))
        ->toBeApiError('forbidden');
});

it('assigns a global role', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $target = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/users/{$target->uuid}/roles", ['role' => 'staff'])
        ->assertCreated();

    expect($target->fresh()->hasPermission('review.moderate'))->toBeTrue();
});

it('revokes a global role', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $target = User::factory()->withRole(RoleKey::Staff)->create();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/admin/users/{$target->uuid}/roles/staff")
        ->assertNoContent();

    expect($target->fresh()->hasPermission('review.moderate'))->toBeFalse();
});

/* Only a Super Admin may mint another Super Admin. */
it('refuses to let an admin create a super admin', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $target = User::factory()->withRole(RoleKey::Student)->create();

    expect($this->actingAs($admin)
        ->postJson("/api/v1/admin/users/{$target->uuid}/roles", ['role' => 'super_admin'])
        ->assertStatus(403))->toBeApiError('forbidden');

    expect($target->fresh()->isSuperAdmin())->toBeFalse();
});

it('lets a super admin create another super admin', function (): void {
    $super = User::factory()->withRole(RoleKey::SuperAdmin)->create();
    $target = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($super)
        ->postJson("/api/v1/admin/users/{$target->uuid}/roles", ['role' => 'super_admin'])
        ->assertCreated();

    expect($target->fresh()->isSuperAdmin())->toBeTrue();
});

/*
 * Demoting the last Super Admin would lock everyone out of role management
 * with no recovery short of database access.
 */
it('refuses to demote the last super admin', function (): void {
    $super = User::factory()->withRole(RoleKey::SuperAdmin)->create();

    expect($this->actingAs($super)
        ->deleteJson("/api/v1/admin/users/{$super->uuid}/roles/super_admin")
        ->assertStatus(422))->toBeApiError('role_assignment_rejected');

    expect($super->fresh()->isSuperAdmin())->toBeTrue();
});

it('allows demoting a super admin when another one remains', function (): void {
    $first = User::factory()->withRole(RoleKey::SuperAdmin)->create();
    $second = User::factory()->withRole(RoleKey::SuperAdmin)->create();

    $this->actingAs($first)
        ->deleteJson("/api/v1/admin/users/{$second->uuid}/roles/super_admin")
        ->assertNoContent();

    expect($second->fresh()->isSuperAdmin())->toBeFalse();
});

it('refuses a course-scoped role without a scope', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $target = User::factory()->withRole(RoleKey::Student)->create();

    expect($this->actingAs($admin)
        ->postJson("/api/v1/admin/users/{$target->uuid}/roles", ['role' => 'teaching_assistant'])
        ->assertStatus(422))->toBeApiError('role_assignment_rejected');
});

/*
 * A scope that cannot be resolved must fail loudly. Silently dropping it would
 * turn "teaching assistant on course 9" into a platform-wide grant.
 *
 * (Courses arrive in Phase 4; the global-vs-scoped mismatch itself is covered
 * by PermissionResolutionTest, which can construct a resolvable scope.)
 */
it('refuses an assignment whose scope target does not exist', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $target = User::factory()->withRole(RoleKey::Student)->create();

    expect($this->actingAs($admin)->postJson("/api/v1/admin/users/{$target->uuid}/roles", [
        'role' => 'teaching_assistant',
        'scope_type' => 'course',
        'scope_id' => 999,
    ])->assertStatus(422))->toBeApiError('role_assignment_rejected');

    expect($target->fresh()->hasRole(RoleKey::TeachingAssistant))->toBeFalse();
});

it('never silently downgrades a scoped request to a global grant', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $target = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($admin)->postJson("/api/v1/admin/users/{$target->uuid}/roles", [
        'role' => 'staff',
        'scope_type' => 'course',
        'scope_id' => 1,
    ])->assertStatus(422);

    expect($target->fresh()->hasRole(RoleKey::Staff))->toBeFalse();
});

it('rejects an unknown role', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $target = User::factory()->withRole(RoleKey::Student)->create();

    expect($this->actingAs($admin)
        ->postJson("/api/v1/admin/users/{$target->uuid}/roles", ['role' => 'wizard'])
        ->assertStatus(422))->toBeApiError('validation_failed');
});

it('rejects an unknown scope type', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $target = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($admin)->postJson("/api/v1/admin/users/{$target->uuid}/roles", [
        'role' => 'teaching_assistant',
        'scope_type' => 'user',
        'scope_id' => 1,
    ])->assertStatus(422);
});

it('denies role assignment to a student', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();
    $target = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($student)
        ->postJson("/api/v1/admin/users/{$target->uuid}/roles", ['role' => 'staff'])
        ->assertStatus(403);
});

it('exposes the permission registry to someone who may view roles', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    $response = $this->actingAs($admin)->getJson('/api/v1/admin/permissions')->assertOk();

    expect(array_keys($response->json('data.groups')))->toContain('courses', 'commerce', 'system');
});
