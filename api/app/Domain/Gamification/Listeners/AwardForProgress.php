<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Listeners;

use App\Domain\Gamification\Actions\EvaluateTrigger;
use App\Domain\Gamification\Data\TriggerContext;
use App\Domain\Gamification\Enums\TriggerEvent;
use App\Domain\Progress\Events\CourseCompleted;
use App\Domain\Progress\Events\ItemCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Progress, as the rule engine sees it.
 *
 * Queued, like every listener here: awarding takes a row lock, and a learner
 * ticking a checkbox must not wait on it. Progress knows nothing about points
 * — it fires and this connects them (CLAUDE.md §4).
 */
final class AwardForProgress implements ShouldQueue
{
    public function __construct(private readonly EvaluateTrigger $evaluate) {}

    public function item(ItemCompleted $event): void
    {
        $this->evaluate->handle(TriggerContext::for(
            TriggerEvent::ItemCompleted,
            $event->enrollment->user_id,
            $event->item,
            ['item_type' => $event->item->type->value],
            $event->enrollment->course_id,
        ));
    }

    public function course(CourseCompleted $event): void
    {
        $this->evaluate->handle(TriggerContext::for(
            TriggerEvent::CourseCompleted,
            $event->enrollment->user_id,
            $event->enrollment,
            [],
            $event->enrollment->course_id,
        ));
    }
}
