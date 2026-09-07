<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Queries\CourseAccess;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Actions\TrackItemProgress;

beforeEach(function (): void {
    seedRegistry();
    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [2]);
    $this->access = app(CourseAccess::class);
});

/*
 * ADR-03: one service answers "may this user consume this content?", so every
 * gate agrees. The audited product answers it in many places and they disagree.
 */

it('denies an anonymous visitor', function (): void {
    $decision = $this->access->for(null, $this->course);

    expect($decision->granted)->toBeFalse()
        ->and($decision->reason)->toBe('unauthenticated');
});

it('denies a signed-in user who is not enrolled', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();

    expect($this->access->for($student, $this->course)->reason)->toBe('not_enrolled');
});

it('grants an enrolled learner', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create(['course_id' => $this->course->id, 'user_id' => $student->id]);

    $decision = $this->access->for($student, $this->course);

    expect($decision->granted)->toBeTrue()
        ->and($decision->source)->toBe('enrollment');
});

/* Evaluated live, not trusted from the status column: the sweeper runs on a
 * schedule and access must not depend on a cron having fired. */
it('denies an expired enrollment even before the sweeper runs', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->expired()->create([
        'course_id' => $this->course->id,
        'user_id' => $student->id,
    ]);

    expect($this->access->for($student, $this->course)->reason)->toBe('enrollment_expired');
});

it('denies a suspended enrollment', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->suspended()->create([
        'course_id' => $this->course->id,
        'user_id' => $student->id,
    ]);

    expect($this->access->for($student, $this->course)->granted)->toBeFalse();
});

/* Completing a course must not lock the learner out of what they paid for. */
it('still grants access after completion', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();
    $enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $student->id,
    ]);

    foreach ($this->course->items as $item) {
        app(TrackItemProgress::class)->complete($enrollment, $item);
    }

    expect($this->access->for($student->fresh(), $this->course)->granted)->toBeTrue();
});

it('grants the course owner without an enrollment', function (): void {
    $owner = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->published()->create();

    expect($this->access->for($owner, $course)->source)->toBe('owner');
});

it('grants a course-scoped manager', function (): void {
    $manager = User::factory()->withRole(RoleKey::Student)->create();
    $manager->assignRole(RoleKey::CourseManager, $this->course);

    expect($this->access->for($manager->fresh(), $this->course)->source)->toBe('course_staff');
});

it('does not grant an unrelated instructor', function (): void {
    $stranger = User::factory()->instructor()->create();

    expect($this->access->for($stranger, $this->course)->granted)->toBeFalse();
});

/* Preview is what makes "try before you buy" work without a second code path. */
it('grants a preview item to anyone', function (): void {
    $item = $this->course->items()->first();
    $item->update(['is_preview' => true]);

    expect($this->access->forItem(null, $item->fresh())->source)->toBe('preview');
});

it('does not grant a non-preview item to an anonymous visitor', function (): void {
    expect($this->access->forItem(null, $this->course->items()->first())->granted)->toBeFalse();
});

it('does not grant a preview item on an unpublished course', function (): void {
    $draft = courseWithCurriculum(Course::factory()->create(), [1]);
    $item = $draft->items()->first();
    $item->update(['is_preview' => true]);

    expect($this->access->forItem(null, $item->fresh())->granted)->toBeFalse();
});
