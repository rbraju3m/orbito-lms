<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(fn () => seedRegistry());

it('lists applications for staff who may view instructors', function (): void {
    User::factory()->instructor(InstructorStatus::Pending)->count(2)->create();
    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    $this->actingAs($admin)->getJson('/api/v1/admin/instructors?status=pending')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page'], 'links']);
});

it('denies the instructor list to a student', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();

    expect($this->actingAs($student)->getJson('/api/v1/admin/instructors')->assertStatus(403))
        ->toBeApiError('forbidden');
});

it('denies the instructor list to an instructor', function (): void {
    $instructor = User::factory()->instructor()->create();

    $this->actingAs($instructor)->getJson('/api/v1/admin/instructors')->assertStatus(403);
});

/* Approval is the ONLY thing that grants the instructor role. */
it('grants the instructor role on approval', function (): void {
    $applicant = User::factory()->instructor(InstructorStatus::Pending)->create();
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $profile = $applicant->instructorProfile;

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/instructors/{$profile->id}/review", ['decision' => 'approved'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $fresh = $applicant->fresh();
    expect($fresh->hasRole(RoleKey::Instructor))->toBeTrue()
        ->and($fresh->hasPermission('course.create'))->toBeTrue()
        ->and($fresh->isApprovedInstructor())->toBeTrue();
});

it('records who reviewed the application and when', function (): void {
    $applicant = User::factory()->instructor(InstructorStatus::Pending)->create();
    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    $this->actingAs($admin)->postJson(
        "/api/v1/admin/instructors/{$applicant->instructorProfile->id}/review",
        ['decision' => 'approved', 'note' => 'Strong portfolio.'],
    )->assertOk();

    $profile = $applicant->fresh()->instructorProfile;
    expect($profile->reviewed_by)->toBe($admin->id)
        ->and($profile->reviewed_at)->not->toBeNull()
        ->and($profile->review_note)->toBe('Strong portfolio.');
});

it('removes the instructor role when an instructor is blocked', function (): void {
    $instructor = User::factory()->instructor()->create();
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    expect($instructor->hasPermission('course.create'))->toBeTrue();

    $this->actingAs($admin)->postJson(
        "/api/v1/admin/instructors/{$instructor->instructorProfile->id}/review",
        ['decision' => 'blocked', 'note' => 'Policy violation.'],
    )->assertOk();

    $fresh = $instructor->fresh();
    expect($fresh->hasRole(RoleKey::Instructor))->toBeFalse()
        ->and($fresh->hasPermission('course.create'))->toBeFalse();
});

it('removes the instructor role on rejection', function (): void {
    $instructor = User::factory()->instructor()->create();
    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    $this->actingAs($admin)->postJson(
        "/api/v1/admin/instructors/{$instructor->instructorProfile->id}/review",
        ['decision' => 'rejected'],
    )->assertOk();

    expect($instructor->fresh()->hasPermission('course.create'))->toBeFalse();
});

it('rejects "pending" as a review decision', function (): void {
    $applicant = User::factory()->instructor(InstructorStatus::Pending)->create();
    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    expect($this->actingAs($admin)->postJson(
        "/api/v1/admin/instructors/{$applicant->instructorProfile->id}/review",
        ['decision' => 'pending'],
    )->assertStatus(422))->toBeApiError('validation_failed');
});

it('denies review to a user without the permission', function (): void {
    $applicant = User::factory()->instructor(InstructorStatus::Pending)->create();
    $staff = User::factory()->withRole(RoleKey::Staff)->create();

    // Staff may VIEW instructors but not approve them.
    expect($this->actingAs($staff)->postJson(
        "/api/v1/admin/instructors/{$applicant->instructorProfile->id}/review",
        ['decision' => 'approved'],
    )->assertStatus(403))->toBeApiError('forbidden');
});
