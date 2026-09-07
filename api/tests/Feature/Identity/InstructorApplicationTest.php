<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(fn () => seedRegistry());

it('lets a student apply to teach', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($student)
        ->postJson('/api/v1/account/instructor-application', ['message' => 'I teach maths.'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    expect($student->fresh()->instructorProfile?->status)->toBe(InstructorStatus::Pending);
});

/* Applying must not grant any teaching capability. */
it('grants no instructor permissions while pending', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($student)->postJson('/api/v1/account/instructor-application')->assertCreated();

    $fresh = $student->fresh();
    expect($fresh->hasRole(RoleKey::Instructor))->toBeFalse()
        ->and($fresh->hasPermission('course.create'))->toBeFalse();
});

it('refuses a second application while one is under review', function (): void {
    $student = User::factory()->instructor(InstructorStatus::Pending)->create();

    expect(
        $this->actingAs($student)->postJson('/api/v1/account/instructor-application')->assertStatus(409)
    )->toBeApiError('instructor_application_conflict');
});

it('refuses an application from an approved instructor', function (): void {
    $instructor = User::factory()->instructor()->create();

    expect(
        $this->actingAs($instructor)->postJson('/api/v1/account/instructor-application')->assertStatus(409)
    )->toBeApiError('instructor_application_conflict');
});

it('refuses an application from a blocked instructor', function (): void {
    $blocked = User::factory()->instructor(InstructorStatus::Blocked)->create();

    expect(
        $this->actingAs($blocked)->postJson('/api/v1/account/instructor-application')->assertStatus(409)
    )->toBeApiError('instructor_application_conflict');
});

it('lets a rejected applicant reapply', function (): void {
    $rejected = User::factory()->instructor(InstructorStatus::Rejected)->create();

    $this->actingAs($rejected)
        ->postJson('/api/v1/account/instructor-application', ['message' => 'Improved my proposal.'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');
});

it('returns 404 when the caller has never applied', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();

    expect($this->actingAs($student)->getJson('/api/v1/account/instructor-application')->assertNotFound())
        ->toBeApiError('not_found');
});
