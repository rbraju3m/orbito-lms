<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Actions;

use App\Domain\Engagement\Enums\ReviewStatus;
use App\Domain\Engagement\Events\ReviewChanged;
use App\Domain\Engagement\Models\Review;

/**
 * Publishes or rejects a review.
 *
 * A rejected review is KEPT, not deleted. The learner wrote it, they can see
 * it is not published, and an academy that could silently delete criticism
 * would make its own rating meaningless. Rejection removes it from the public
 * page and from the average; it does not remove it from the record.
 */
final class ModerateReview
{
    // No RecalculateCourseRating dependency: the recount happens through
    // ReviewChanged, so every path that moves a rating goes the same way.
    public function handle(Review $review, ReviewStatus $status): Review
    {
        $review->forceFill([
            'status' => $status,
            /*
             * Set on first publish and never moved afterwards: "published on"
             * is when it first appeared, and a review that is unpublished and
             * republished has not been written twice.
             */
            'published_at' => $status === ReviewStatus::Published
                ? ($review->published_at ?? now())
                : $review->published_at,
        ])->save();

        ReviewChanged::dispatch($review->course_id);

        return $review->refresh();
    }
}
