<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Listeners;

use App\Domain\Analytics\Actions\RecordEvent;
use App\Domain\Analytics\Data\EventData;
use App\Domain\Analytics\Enums\EventName;
use App\Domain\Assessment\Events\AssignmentGraded;
use App\Domain\Assessment\Events\AssignmentSubmitted;
use App\Domain\Assessment\Events\QuizAttemptGraded;
use App\Domain\Assessment\Events\QuizAttemptSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Assessment, as the log sees it.
 *
 * `quiz_passed` is raised from GRADED rather than from submitted, and only
 * when it passed. Submitting and passing are different facts that arrive at
 * different times — an essay question puts hours between them — so a report
 * that counted a submission as a pass would flatter every course with open
 * questions in it.
 */
final class RecordAssessmentEvents implements ShouldQueue
{
    public function __construct(private readonly RecordEvent $record) {}

    public function quizSubmitted(QuizAttemptSubmitted $event): void
    {
        $attempt = $event->attempt;

        $this->record->handle(new EventData(
            name: EventName::QuizSubmitted,
            occurredAt: $attempt->submitted_at,
            actorId: $attempt->user_id,
            subjectType: $attempt->getMorphClass(),
            subjectId: $attempt->id,
            courseId: $attempt->course_id,
            courseItemId: $attempt->course_item_id,
            properties: ['attempt_number' => $attempt->attempt_number],
        ));
    }

    public function quizGraded(QuizAttemptGraded $event): void
    {
        if (! $event->passed) {
            // A failure is already countable: submitted minus passed. Logging
            // it as its own name would be a second definition of the same
            // number, free to drift.
            return;
        }

        $attempt = $event->attempt;

        $this->record->handle(new EventData(
            name: EventName::QuizPassed,
            actorId: $attempt->user_id,
            subjectType: $attempt->getMorphClass(),
            subjectId: $attempt->id,
            courseId: $attempt->course_id,
            courseItemId: $attempt->course_item_id,
            properties: [
                // A string on the model — it is a DECIMAL column, kept out of
                // float arithmetic (ADR-04's instinct, applied to scores).
                'percent' => (float) $attempt->percent,
                'attempt_number' => $attempt->attempt_number,
            ],
        ));
    }

    public function assignmentSubmitted(AssignmentSubmitted $event): void
    {
        $submission = $event->submission;

        $this->record->handle(new EventData(
            name: EventName::AssignmentSubmitted,
            occurredAt: $submission->submitted_at,
            actorId: $submission->user_id,
            subjectType: $submission->getMorphClass(),
            subjectId: $submission->id,
            courseId: $submission->course_id,
            courseItemId: $submission->course_item_id,
            // Settled at submission time and frozen there (§14), so the log
            // agrees with the submission rather than re-deciding it.
            properties: ['is_late' => $submission->is_late],
        ));
    }

    public function assignmentGraded(AssignmentGraded $event): void
    {
        $submission = $event->submission;

        $this->record->handle(new EventData(
            name: EventName::AssignmentGraded,
            occurredAt: $submission->graded_at,
            // The LEARNER, not the grader. `actor_id` answers "whose activity
            // was this?"; a grading throughput report reads the submission.
            actorId: $submission->user_id,
            subjectType: $submission->getMorphClass(),
            subjectId: $submission->id,
            courseId: $submission->course_id,
            courseItemId: $submission->course_item_id,
            properties: [
                'passed' => $event->passed,
                // Absent is not zero (§14) — but a graded submission has a
                // real number, so this one is never a guess.
                'points_earned' => $submission->points_earned,
            ],
        ));
    }
}
