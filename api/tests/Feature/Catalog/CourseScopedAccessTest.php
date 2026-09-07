<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\CourseInstructorRole;
use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(fn () => seedRegistry());

/*
 * These are the tests that close ADR-07's loop now that a Course exists to
 * scope a role to — and the regression guard for a hole found while building
 * this phase: `hasPermission($key, $scope)` returns global ∪ scoped, so asking
 * it "do they have a seat here?" made EVERY instructor staff on EVERY course.
 */

it('does not let one instructor edit another instructor course', function (): void {
    $owner = User::factory()->instructor()->create();
    $stranger = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    // The stranger holds course.update.own globally. That must not apply here.
    expect($stranger->hasPermission('course.update.own'))->toBeTrue();

    $this->actingAs($stranger)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}", ['title' => 'Hijacked'])
        ->assertStatus(403);
});

it('does not let one instructor read another unpublished course', function (): void {
    $owner = User::factory()->instructor()->create();
    $stranger = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $this->actingAs($stranger)->getJson("/api/v1/studio/courses/{$course->uuid}")->assertStatus(403);
});

it('does not let one instructor publish another course', function (): void {
    $owner = User::factory()->instructor()->create();
    $stranger = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->publishable()->create();

    $this->actingAs($stranger)
        ->postJson("/api/v1/studio/courses/{$course->uuid}/publish")
        ->assertStatus(403);
});

it('grants a course manager rights on their assigned course only', function (): void {
    $owner = User::factory()->instructor()->create();
    $manager = User::factory()->withRole(RoleKey::Student)->create();

    $assigned = Course::factory()->ownedBy($owner)->withCategory()->create();
    $other = Course::factory()->ownedBy($owner)->withCategory()->create();

    $manager->assignRole(RoleKey::CourseManager, $assigned);

    $this->actingAs($manager)
        ->patchJson("/api/v1/studio/courses/{$assigned->uuid}", ['title' => 'Managed and renamed'])
        ->assertOk();

    $this->actingAs($manager)
        ->patchJson("/api/v1/studio/courses/{$other->uuid}", ['title' => 'Not mine'])
        ->assertStatus(403);
});

it('lets a course manager publish their assigned course', function (): void {
    $owner = User::factory()->instructor()->create();
    $manager = User::factory()->withRole(RoleKey::Student)->create();
    $course = Course::factory()->ownedBy($owner)->publishable()->create();

    $manager->assignRole(RoleKey::CourseManager, $course);

    $this->actingAs($manager)
        ->postJson("/api/v1/studio/courses/{$course->uuid}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');
});

it('lets a teaching assistant read their course but not edit it', function (): void {
    $owner = User::factory()->instructor()->create();
    $ta = User::factory()->withRole(RoleKey::Student)->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $ta->assignRole(RoleKey::TeachingAssistant, $course);

    $this->actingAs($ta)->getJson("/api/v1/studio/courses/{$course->uuid}")->assertOk();

    $this->actingAs($ta)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}", ['title' => 'TA overreach'])
        ->assertStatus(403);
});

it('lets a reviewer approve a submission but not their own course', function (): void {
    $owner = User::factory()->instructor()->create();
    $reviewer = User::factory()->withRole(RoleKey::Student)->create();

    $course = Course::factory()->ownedBy($owner)->publishable()->inReview()->create();
    $reviewer->assignRole(RoleKey::CourseReviewer, $course);

    $this->actingAs($reviewer)
        ->postJson("/api/v1/studio/courses/{$course->uuid}/approve-review")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');
});

it('does not let an author approve their own submission', function (): void {
    $owner = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->publishable()->inReview()->create();

    // Even granted the reviewer role on their own course, self-approval defeats
    // the point of review.
    $owner->assignRole(RoleKey::CourseReviewer, $course);

    $this->actingAs($owner)
        ->postJson("/api/v1/studio/courses/{$course->uuid}/approve-review")
        ->assertStatus(403);
});

it('lets a co-instructor edit the course they are seated on', function (): void {
    $owner = User::factory()->instructor()->create();
    $co = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $course->instructors()->create([
        'user_id' => $co->id,
        'role' => CourseInstructorRole::CoInstructor,
        'position' => 1,
    ]);

    $this->actingAs($co)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}", ['title' => 'Co-authored'])
        ->assertOk();
});

it('revokes access the moment a scoped role is removed', function (): void {
    $owner = User::factory()->instructor()->create();
    $manager = User::factory()->withRole(RoleKey::Student)->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $manager->assignRole(RoleKey::CourseManager, $course);
    $this->actingAs($manager)->getJson("/api/v1/studio/courses/{$course->uuid}")->assertOk();

    $manager->revokeRole(RoleKey::CourseManager, $course);
    $this->actingAs($manager->fresh())->getJson("/api/v1/studio/courses/{$course->uuid}")->assertStatus(403);
});
