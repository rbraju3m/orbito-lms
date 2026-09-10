<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Enums\CourseVisibility;
use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;

/**
 * `.own` permissions are answered against the course as a SCOPE, so a course
 * -scoped role (Course Manager, Reviewer, TA) grants rights on that course and
 * nothing else — this is where ADR-07 pays off.
 */
final class CoursePolicy
{
    public function viewAny(?User $actor): bool
    {
        return true; // The catalogue is public; the query filters by status.
    }

    /** Reading the course page, not its paid content. */
    public function view(?User $actor, Course $course): bool
    {
        if ($course->status === CourseStatus::Published
            && $course->visibility !== CourseVisibility::Private) {
            return true;
        }

        return $actor !== null && $this->canSeeUnpublished($actor, $course);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('course.create');
    }

    public function update(User $actor, Course $course): bool
    {
        return $actor->hasPermission('course.update.any')
            || ($this->isCourseStaff($actor, $course) && $actor->hasPermission('course.update.own', $course));
    }

    public function delete(User $actor, Course $course): bool
    {
        return $actor->hasPermission('course.delete.any')
            || ($course->owner_id === $actor->id && $actor->hasPermission('course.delete.own'));
    }

    public function publish(User $actor, Course $course): bool
    {
        return $actor->hasPermission('course.publish.any')
            || ($this->isCourseStaff($actor, $course) && $actor->hasPermission('course.publish.own', $course));
    }

    public function submitForReview(User $actor, Course $course): bool
    {
        return $this->isCourseStaff($actor, $course)
            && $actor->hasPermission('course.review.submit', $course);
    }

    /** Approving your own submission would defeat the point of review. */
    public function reviewSubmission(User $actor, Course $course): bool
    {
        if ($actor->hasPermission('course.review.approve')) {
            return true;
        }

        return $actor->hasPermission('course.review.approve', $course)
            && $course->owner_id !== $actor->id;
    }

    public function archive(User $actor, Course $course): bool
    {
        return $actor->hasPermission('course.delete.any')
            || ($this->isCourseStaff($actor, $course) && $actor->hasPermission('course.archive', $course));
    }

    public function manageInstructors(User $actor, Course $course): bool
    {
        return $actor->hasPermission('course.update.any')
            || ($this->isCourseStaff($actor, $course)
                && $actor->hasPermission('course.instructors.manage', $course));
    }

    /**
     * Setting what a course costs.
     *
     * Its own permission rather than part of `update`, because what a course
     * earns is a different decision from what it says — an academy can let a
     * TA fix a typo without letting them halve the price.
     */
    public function price(User $actor, Course $course): bool
    {
        return $actor->hasPermission('course.price.any')
            || ($this->isCourseStaff($actor, $course) && $actor->hasPermission('course.price.own', $course));
    }

    public function manageSettings(User $actor, Course $course): bool
    {
        return $actor->hasPermission('course.update.any')
            || ($this->isCourseStaff($actor, $course)
                && $actor->hasPermission('course.settings.manage', $course));
    }

    public function viewUnpublished(User $actor, Course $course): bool
    {
        return $this->canSeeUnpublished($actor, $course);
    }

    private function canSeeUnpublished(User $actor, Course $course): bool
    {
        if ($actor->hasPermission('course.view.any')) {
            return true;
        }

        // Either a seat on the course, or a course-scoped role granted on it.
        // The second clause MUST be scoped-only: every instructor holds
        // `course.view.unpublished` globally, so a union check here would let
        // any instructor read any unpublished course.
        return ($this->isCourseStaff($actor, $course)
                && $actor->hasPermission('course.view.unpublished'))
            || $actor->hasScopedPermission('course.view.unpublished', $course);
    }

    /**
     * A seat on THIS course: owner, co-instructor, or a course-scoped role
     * assignment granted on it.
     *
     * Deliberately uses hasAnyScopedPermission, not hasAnyPermission: the
     * latter unions in global roles, and every instructor holds these keys
     * globally — which would make every instructor staff on every course.
     */
    private function isCourseStaff(User $actor, Course $course): bool
    {
        return $course->isStaffedBy($actor)
            || $actor->hasAnyScopedPermission(
                ['course.view.unpublished', 'curriculum.manage.own', 'quiz.grade.own'],
                $course,
            );
    }
}
