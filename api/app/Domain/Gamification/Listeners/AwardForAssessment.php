<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Listeners;

use App\Domain\Assessment\Events\AssignmentGraded;
use App\Domain\Assessment\Events\QuizAttemptGraded;
use App\Domain\Gamification\Actions\EvaluateTrigger;
use App\Domain\Gamification\Data\TriggerContext;
use App\Domain\Gamification\Enums\TriggerEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Assessment, as the rule engine sees it.
 *
 * A FAILED attempt raises nothing at all — not a trigger with `passed: false`
 * for a rule to filter out. A trigger that fires on failure is a trigger some
 * academy will accidentally attach points to, and paying for failing a quiz
 * is a scheme that rewards guessing.
 *
 * The score travels in the payload so a rule can require a real pass rather
 * than a scrape, and it is the score AT THE MOMENT it was graded: the rule
 * never re-reads the attempt.
 */
final class AwardForAssessment implements ShouldQueue
{
    public function __construct(private readonly EvaluateTrigger $evaluate) {}

    public function quiz(QuizAttemptGraded $event): void
    {
        if (! $event->passed) {
            return;
        }

        $attempt = $event->attempt;

        $this->evaluate->handle(TriggerContext::for(
            TriggerEvent::QuizPassed,
            $attempt->user_id,
            $attempt,
            ['percent' => (float) $attempt->percent],
            $attempt->course_id,
        ));
    }

    public function assignment(AssignmentGraded $event): void
    {
        $submission = $event->submission;

        $this->evaluate->handle(TriggerContext::for(
            TriggerEvent::AssignmentGraded,
            $submission->user_id,
            $submission,
            [
                'passed' => $event->passed,
                'points_earned' => $submission->points_earned,
                // Settled at submission time and frozen there (§14), so a rule
                // reading it agrees with the submission.
                'is_late' => $submission->is_late,
            ],
            $submission->course_id,
        ));
    }
}
