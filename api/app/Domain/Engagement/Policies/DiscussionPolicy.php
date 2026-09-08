<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Policies;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Models\Discussion;
use App\Domain\Engagement\Models\DiscussionReply;
use App\Domain\Enrollment\Queries\CourseAccess;
use App\Domain\Identity\Models\User;

/**
 * Who may read, post in and moderate a thread.
 *
 * POSTING resolves through `CourseAccess` (ADR-03), not through the enrolment
 * table directly — unlike reviews. The difference is deliberate and worth
 * naming: a review is a verdict on a course you took, so an expired learner
 * may still write one; a question is participation in a course you are
 * currently taking, and somebody whose access lapsed is no longer in the room.
 */
final class DiscussionPolicy
{
    public function __construct(private readonly CourseAccess $access) {}

    /** Reading the Q&A of a course. */
    public function viewAny(User $actor, Course $course): bool
    {
        return $this->access->for($actor, $course)->granted;
    }

    public function view(User $actor, Discussion $discussion): bool
    {
        if (! $discussion->status->isVisible()) {
            // A hidden thread is visible to moderators only. Its author does
            // not get to read it either — that is what hiding means.
            return $this->moderate($actor, $discussion);
        }

        return $this->viewAny($actor, $discussion->loadMissing('course')->course);
    }

    public function create(User $actor, Course $course): bool
    {
        return $this->access->for($actor, $course)->granted
            && $actor->hasPermission('discussion.create');
    }

    public function reply(User $actor, Discussion $discussion): bool
    {
        $course = $discussion->loadMissing('course')->course;

        return $discussion->status->isVisible()
            && $this->access->for($actor, $course)->granted
            && $actor->hasPermission('discussion.reply');
    }

    /**
     * Accepting an answer.
     *
     * The ASKER, or course staff. Not any moderator: choosing which reply
     * answered your question is a judgement only you and the people teaching
     * the course can make, and a platform moderator marking it would be
     * putting words in somebody's mouth.
     */
    public function accept(User $actor, Discussion $discussion): bool
    {
        return $discussion->user_id === $actor->id
            || $this->isCourseStaff($actor, $discussion->loadMissing('course')->course);
    }

    /** Hiding, pinning, and unhiding. */
    public function moderate(User $actor, Discussion $discussion): bool
    {
        $course = $discussion->loadMissing('course')->course;

        return $actor->hasPermission('discussion.moderate')
            && ($this->access->for($actor, $course)->isStaff()
                || $actor->hasPermission('discussion.moderate', $course));
    }

    /** Deleting one's own words, or a moderator removing somebody's. */
    public function deleteReply(User $actor, DiscussionReply $reply): bool
    {
        if ($reply->user_id === $actor->id) {
            return true;
        }

        return $this->moderate($actor, $reply->loadMissing('discussion.course')->discussion);
    }

    /**
     * Scoped-only, because every instructor holds the `.own`-style keys
     * globally — a union check would make every instructor staff on every
     * course (CourseScopedAccessTest).
     */
    private function isCourseStaff(User $actor, Course $course): bool
    {
        return $course->isStaffedBy($actor)
            || $actor->hasAnyScopedPermission(['discussion.moderate'], $course);
    }
}
