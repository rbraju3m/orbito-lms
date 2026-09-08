<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Policies;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Models\Review;
use App\Domain\Identity\Models\User;

/**
 * Who may write, edit, answer and moderate a review.
 */
final class ReviewPolicy
{
    /**
     * Editing one's own words, indefinitely.
     *
     * No time limit, deliberately: a course that got worse after an update
     * should be re-reviewable by the person who already reviewed it, and a
     * window would freeze praise written in the first week.
     */
    public function update(User $actor, Review $review): bool
    {
        return $review->user_id === $actor->id;
    }

    public function delete(User $actor, Review $review): bool
    {
        return $review->user_id === $actor->id
            || $actor->hasPermission('review.delete');
    }

    /** Publishing or rejecting somebody else's words. */
    public function moderate(User $actor, Review $review): bool
    {
        return $actor->hasPermission('review.moderate');
    }

    /**
     * The public answer under a review.
     *
     * Scoped-only for course staff: every instructor holds `review.reply.own`
     * globally, so a union check would let any instructor answer reviews on
     * any course in the academy. Same trap as CoursePolicy::isCourseStaff —
     * see CourseScopedAccessTest.
     */
    public function reply(User $actor, Review $review): bool
    {
        $course = $review->loadMissing('course')->course;

        return $actor->hasPermission('review.moderate')
            || ($this->isCourseStaff($actor, $course)
                && $actor->hasPermission('review.reply.own', $course));
    }

    private function isCourseStaff(User $actor, Course $course): bool
    {
        return $course->isStaffedBy($actor)
            || $actor->hasAnyScopedPermission(['review.reply.own'], $course);
    }
}
