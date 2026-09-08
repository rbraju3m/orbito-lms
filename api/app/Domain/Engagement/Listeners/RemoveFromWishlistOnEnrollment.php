<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Listeners;

use App\Domain\Engagement\Models\WishlistItem;
use App\Domain\Enrollment\Events\CourseEnrolled;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A wishlist of things you already have is noise.
 *
 * Enrolling is the wish being granted, so the saved entry goes. Queued,
 * because nothing about the enrolment depends on it and a learner should not
 * wait on tidying — and it is idempotent, so a retry is harmless.
 *
 * Note this does NOT fire on unenrolment. Losing access does not mean you
 * wanted the course back on a list you last touched a year ago.
 */
final class RemoveFromWishlistOnEnrollment implements ShouldQueue
{
    public function handle(CourseEnrolled $event): void
    {
        WishlistItem::query()
            ->where('user_id', $event->enrollment->user_id)
            ->where('course_id', $event->enrollment->course_id)
            ->delete();
    }
}
