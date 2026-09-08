<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Listeners;

use App\Domain\Engagement\Actions\RecalculateCourseRating;
use App\Domain\Engagement\Events\ReviewChanged;

/**
 * Maintains the denormalised rating.
 *
 * NOT queued, unlike most listeners here. A learner who publishes a review and
 * immediately sees the course still showing the old average will assume the
 * write failed — and the work is one indexed aggregate over one course, which
 * is cheaper than the round trip to a queue. Contrast RecountEnrollmentTotals,
 * which touches every enrolled learner and must be queued.
 */
final class RefreshCourseRating
{
    public function __construct(private readonly RecalculateCourseRating $recalculate) {}

    public function handle(ReviewChanged $event): void
    {
        $this->recalculate->forCourseId($event->courseId);
    }
}
