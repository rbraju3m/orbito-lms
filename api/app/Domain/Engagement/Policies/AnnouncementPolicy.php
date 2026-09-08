<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Policies;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Models\Announcement;
use App\Domain\Enrollment\Queries\CourseAccess;
use App\Domain\Identity\Models\User;

/**
 * Announcements are written by course staff and read by everybody with access.
 */
final class AnnouncementPolicy
{
    public function __construct(private readonly CourseAccess $access) {}

    /** Reading the list. A draft never appears here; the query excludes it. */
    public function viewAny(User $actor, Course $course): bool
    {
        return $this->access->for($actor, $course)->granted;
    }

    public function view(User $actor, Announcement $announcement): bool
    {
        $course = $announcement->loadMissing('course')->course;

        // A draft is visible only to the people who can publish it.
        if (! $announcement->isPublished()) {
            return $this->manage($actor, $course);
        }

        return $this->viewAny($actor, $course);
    }

    /**
     * Writing, publishing and deleting.
     *
     * Scoped-only for course staff: every instructor holds
     * `announcement.manage` globally, so a union check would let any
     * instructor announce to any course's students in the academy — the
     * CourseScopedAccessTest trap, and a particularly loud one here because
     * the result is an email to strangers.
     */
    public function manage(User $actor, Course $course): bool
    {
        if (! $actor->hasPermission('announcement.manage')) {
            return false;
        }

        return $this->access->for($actor, $course)->isStaff()
            || $actor->hasAnyScopedPermission(['announcement.manage'], $course);
    }
}
