<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Enums\AttemptResult;
use App\Domain\Assessment\Enums\AttemptStatus;
use App\Domain\Assessment\Models\QuizAttempt;
use App\Domain\Assessment\Models\QuizAttemptAnswer;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The manual grading queue: an instructor scores the open questions.
 *
 * Once none remain the attempt is finalised — the same path an auto-graded
 * attempt takes, so downstream listeners see one consistent event.
 */
final class GradeAnswerManually
{
    public function __construct(private readonly SubmitQuizAttempt $submit) {}

    /**
     * @param  list<array{question_id: int, points: float, feedback?: string|null}>  $grades
     */
    public function handle(QuizAttempt $attempt, array $grades, User $grader): QuizAttempt
    {
        $attempt->loadMissing(['quiz', 'answers']);

        DB::transaction(function () use ($attempt, $grades, $grader): void {
            foreach ($grades as $grade) {
                $answer = $attempt->answers->firstWhere('question_id', $grade['question_id']);

                if ($answer === null) {
                    continue;
                }

                $possible = (float) $answer->points_possible;
                // Clamp: a typo must not award more than the question is worth.
                $points = max(0.0, min((float) $grade['points'], $possible));

                $answer->forceFill([
                    'points_earned' => $points,
                    'is_correct' => $possible > 0 ? $points >= $possible : true,
                    'feedback' => $grade['feedback'] ?? $answer->feedback,
                    'graded_by' => $grader->id,
                    'graded_at' => now(),
                ])->save();
            }

            $attempt->load('answers');

            $outstanding = $attempt->answers->contains(
                fn (QuizAttemptAnswer $a) => $a->awaitsReview()
            );

            $earned = max(0.0, (float) $attempt->answers->sum(fn ($a) => (float) $a->points_earned));
            $total = (float) $attempt->total_points;
            $percent = $total > 0 ? round(($earned / $total) * 100, 2) : 0.0;

            $attempt->forceFill([
                'earned_points' => $earned,
                'percent' => $percent,
                'status' => $outstanding ? AttemptStatus::AwaitingReview : AttemptStatus::Graded,
                'graded_at' => $outstanding ? null : now(),
                'graded_by' => $grader->id,
                'result' => $outstanding
                    ? AttemptResult::Pending
                    : ($percent >= $attempt->quiz->passing_score_percent
                        ? AttemptResult::Pass
                        : AttemptResult::Fail),
            ])->save();
        });

        $attempt->refresh();

        if ($attempt->status === AttemptStatus::Graded) {
            $this->submit->finalise($attempt);
        }

        return $attempt;
    }
}
