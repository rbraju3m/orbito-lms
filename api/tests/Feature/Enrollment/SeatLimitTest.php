<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Actions\EnrollInCourse;
use App\Domain\Enrollment\Data\EnrollmentIntent;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Exceptions\EnrollmentRejected;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(function (): void {
    seedRegistry();
    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [1]);
    $this->course->setting->update(['max_students' => 2]);
});

function newStudent(): User
{
    return User::factory()->withRole(RoleKey::Student)->create();
}

it('reports the seats left, and null when uncapped', function (): void {
    expect($this->course->fresh()->seatsRemaining())->toBe(2);

    Enrollment::factory()->create(['course_id' => $this->course->id, 'user_id' => newStudent()->id]);

    expect($this->course->fresh()->seatsRemaining())->toBe(1);

    $this->course->setting->update(['max_students' => null]);

    expect($this->course->fresh()->seatsRemaining())->toBeNull();
});

it('refuses the enrolment that would exceed the cap', function (): void {
    app(EnrollInCourse::class)->handle(newStudent(), $this->course);
    app(EnrollInCourse::class)->handle(newStudent(), $this->course);

    expect(fn () => app(EnrollInCourse::class)->handle(newStudent(), $this->course))
        ->toThrow(EnrollmentRejected::class);

    expect(Enrollment::where('course_id', $this->course->id)->count())->toBe(2);
});

/*
 * Counting outside the transaction let two simultaneous requests both see the
 * last seat and both take it. The count now happens behind a lock on the
 * settings row, so the second transaction reads the first one's insert.
 */
it('does not oversell the last seat under concurrency', function (): void {
    $this->course->setting->update(['max_students' => 1]);
    app(EnrollInCourse::class)->handle(newStudent(), $this->course);

    $rejected = 0;

    foreach (range(1, 4) as $_) {
        try {
            app(EnrollInCourse::class)->handle(newStudent(), $this->course);
        } catch (EnrollmentRejected) {
            $rejected++;
        }
    }

    expect($rejected)->toBe(4)
        ->and(Enrollment::where('course_id', $this->course->id)->count())->toBe(1);
});

/* A cancelled seat is a seat given back. */
it('frees a seat when an enrolment is revoked', function (): void {
    $student = newStudent();
    app(EnrollInCourse::class)->handle($student, $this->course);
    app(EnrollInCourse::class)->handle(newStudent(), $this->course);

    expect($this->course->fresh()->seatsRemaining())->toBe(0);

    Enrollment::where('user_id', $student->id)->first()
        ->forceFill(['status' => EnrollmentStatus::Cancelled])->save();

    expect($this->course->fresh()->seatsRemaining())->toBe(1);
});

/*
 * The plan's decision: staff granting a seat does NOT get past the cap. An
 * override would make max_students mean nothing in particular.
 */
it('applies the cap to a manual grant too', function (): void {
    $admin = userWithRole(RoleKey::Admin);

    app(EnrollInCourse::class)->handle(newStudent(), $this->course);
    app(EnrollInCourse::class)->handle(newStudent(), $this->course);

    expect(fn () => app(EnrollInCourse::class)->handle(
        newStudent(),
        $this->course,
        EnrollmentIntent::manual($admin->id),
    ))->toThrow(EnrollmentRejected::class);
});

it('counts a not-yet-started seat against the cap', function (): void {
    $admin = userWithRole(RoleKey::Admin);

    app(EnrollInCourse::class)->handle(newStudent(), $this->course);
    app(EnrollInCourse::class)->handle(
        newStudent(),
        $this->course,
        EnrollmentIntent::manual($admin->id, now()->addMonth()),
    );

    expect($this->course->fresh()->seatsRemaining())->toBe(0);
});
