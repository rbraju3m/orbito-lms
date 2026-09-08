<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Listeners;

use App\Domain\Analytics\Actions\RecordEvent;
use App\Domain\Analytics\Data\EventData;
use App\Domain\Analytics\Enums\EventName;
use App\Domain\Enrollment\Events\CourseEnrolled;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Enrolment, as the log sees it.
 *
 * Queued, like every listener in this context: analytics OBSERVES the system
 * and must never be able to slow or break it. `occurred_at` is taken from the
 * enrolment rather than from `now()`, because this may land minutes later and
 * a row in the wrong day is a wrong figure in a report nobody will question.
 */
final class RecordEnrollmentEvents implements ShouldQueue
{
    public function __construct(private readonly RecordEvent $record) {}

    public function handle(CourseEnrolled $event): void
    {
        $enrollment = $event->enrollment;

        $this->record->handle(new EventData(
            name: EventName::CourseEnrolled,
            occurredAt: $enrollment->enrolled_at,
            actorId: $enrollment->user_id,
            subjectType: $enrollment->getMorphClass(),
            subjectId: $enrollment->id,
            courseId: $enrollment->course_id,
            // How they got in — bought, granted, self-enrolled. The revenue
            // report and the free-enrolment count are the same question asked
            // with different filters on this one property.
            properties: ['source' => $enrollment->source->value],
        ));
    }
}
