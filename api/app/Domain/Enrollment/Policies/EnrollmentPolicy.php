<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Policies;

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;

/**
 * Who may see and change other people's enrollments.
 *
 * Course-level questions ("may they see this roster?") are Gates over a Course,
 * matching QuizPolicy and CurriculumPolicy. Row-level ones are the registered
 * policy on the model, so `Gate::authorize('update', $enrollment)` works.
 */
final class EnrollmentPolicy
{
    /** The student roster for one course. */
    public function viewRoster(User $actor, Course $course): bool
    {
        return $actor->hasPermission('enrollment.view.any')
            || ($this->isCourseStaff($actor, $course)
                && $actor->hasPermission('enrollment.view.own', $course));
    }

    /** Granting one seat by hand. */
    public function manage(User $actor, Course $course): bool
    {
        return $actor->hasPermission('enrollment.view.any')
            ? $actor->hasPermission('enrollment.create')
            : ($this->isCourseStaff($actor, $course) && $actor->hasPermission('enrollment.create', $course));
    }

    /** Granting many at once — a separate, more dangerous capability. */
    public function bulk(User $actor, Course $course): bool
    {
        return $this->manage($actor, $course) && $actor->hasPermission('enrollment.bulk', $course);
    }

    public function view(User $actor, Enrollment $enrollment): bool
    {
        if ($enrollment->user_id === $actor->id) {
            return true;
        }

        return $this->viewRoster($actor, $enrollment->loadMissing('course')->course);
    }

    /** Suspend, reinstate, extend, revoke — all one capability. */
    public function update(User $actor, Enrollment $enrollment): bool
    {
        $course = $enrollment->loadMissing('course')->course;

        return $actor->hasPermission('enrollment.view.any')
            ? $actor->hasPermission('enrollment.suspend')
            : ($this->isCourseStaff($actor, $course) && $actor->hasPermission('enrollment.suspend', $course));
    }

    /*
     * There is deliberately no `delete`. Revocation sets the status to
     * cancelled and keeps the row: the progress, the quiz attempts and the
     * graded work are a record of what somebody did, and closing their access
     * is not a reason to destroy it. `enrollment.delete` stays declared in
     * config/permissions.php for the Phase 19 data-erasure path, which is a
     * different operation from taking a seat away.
     */

    /**
     * A seat on THIS course. Scoped-only by design: every instructor holds the
     * `.own` keys globally, so a union check here would hand every instructor
     * the roster of every course in the system. Same trap as
     * CoursePolicy::isCourseStaff — see CourseScopedAccessTest.
     */
    private function isCourseStaff(User $actor, Course $course): bool
    {
        return $course->isStaffedBy($actor)
            || $actor->hasAnyScopedPermission(
                ['enrollment.view.own', 'enrollment.create', 'enrollment.suspend'],
                $course,
            );
    }
}
