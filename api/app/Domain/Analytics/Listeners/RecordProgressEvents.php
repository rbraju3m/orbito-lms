<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Listeners;

use App\Domain\Analytics\Actions\RecordEvent;
use App\Domain\Analytics\Data\EventData;
use App\Domain\Analytics\Enums\EventName;
use App\Domain\Progress\Events\CourseCompleted;
use App\Domain\Progress\Events\ItemCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Completion, however it was earned.
 *
 * `ItemCompleted` fires the same way whether the learner pressed a button,
 * watched past the threshold, submitted a quiz or handed in an assignment —
 * and this listener deliberately does not care which. That is the whole
 * reason the event is coarse (docs/EVENTS.md): the funnel wants "did they
 * finish this item?", not the mechanism.
 */
final class RecordProgressEvents implements ShouldQueue
{
    public function __construct(private readonly RecordEvent $record) {}

    public function item(ItemCompleted $event): void
    {
        $enrollment = $event->enrollment;
        $item = $event->item;

        $this->record->handle(new EventData(
            name: EventName::ItemCompleted,
            actorId: $enrollment->user_id,
            subjectType: $item->getMorphClass(),
            subjectId: $item->id,
            courseId: $enrollment->course_id,
            courseItemId: $item->id,
            properties: ['item_type' => $item->type->value],
        ));
    }

    public function course(CourseCompleted $event): void
    {
        $enrollment = $event->enrollment;

        $this->record->handle(new EventData(
            name: EventName::CourseCompleted,
            occurredAt: $enrollment->completed_at,
            actorId: $enrollment->user_id,
            subjectType: $enrollment->getMorphClass(),
            subjectId: $enrollment->id,
            courseId: $enrollment->course_id,
            /*
             * How long it took, settled here rather than derived later: a
             * report computing it from two rows would have to find the
             * enrolment, and the enrolment may since have been deleted.
             */
            properties: [
                'days_to_complete' => $enrollment->completed_at === null
                    ? null
                    : $enrollment->enrolled_at->diffInDays($enrollment->completed_at),
            ],
        ));
    }
}
