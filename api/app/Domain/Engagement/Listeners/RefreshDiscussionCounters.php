<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Listeners;

use App\Domain\Engagement\Actions\RecalculateDiscussionCounters;
use App\Domain\Engagement\Events\DiscussionReplied;

/**
 * Not queued, for the same reason RefreshCourseRating is not: somebody who
 * posts a reply and still sees "0 replies" will assume it failed, and the
 * work is one indexed aggregate over one thread.
 */
final class RefreshDiscussionCounters
{
    public function __construct(private readonly RecalculateDiscussionCounters $recalculate) {}

    public function handle(DiscussionReplied $event): void
    {
        $this->recalculate->forDiscussionId($event->discussionId);
    }
}
