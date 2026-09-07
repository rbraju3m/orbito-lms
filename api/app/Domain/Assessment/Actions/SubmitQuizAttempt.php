<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Enums\AttemptResult;
use App\Domain\Assessment\Enums\AttemptStatus;
use App\Domain\Assessment\Enums\TimeExpiryPolicy;
use App\Domain\Assessment\Events\QuizAttemptGraded;
use App\Domain\Assessment\Events\QuizAttemptSubmitted;
use App\Domain\Assessment\Exceptions\AttemptRejected;
use App\Domain\Assessment\Grading\QuestionGrader;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuizAttempt;
use App\Domain\Assessment\Models\QuizAttemptAnswer;
use App\Domain\Progress\Actions\TrackItemProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Grades an attempt, entirely server-side.
 *
 * Also the single place a timed-out attempt is resolved: whether the learner
 * pressed submit, the sweeper found it, or the client never came back, the
 * outcome is decided by the deadline the server recorded at start (ADR-06).
 */
final class SubmitQuizAttempt
{
    public function __construct(
        private readonly QuestionGrader $grader,
        private readonly TrackItemProgress $progress,
    ) {}

    public function handle(QuizAttempt $attempt, bool $viaSweeper = false): QuizAttempt
    {
        if (! $attempt->status->isOpen()) {
            if ($viaSweeper) {
                return $attempt;
            }

            throw AttemptRejected::alreadySubmitted();
        }

        $attempt->loadMissing(['quiz.questions.options', 'answers', 'enrollment', 'item']);
        $quiz = $attempt->quiz;

        // A submission that arrives after the deadline is treated as the
        // expiry policy says — the client's clock has no say.
        $lateSubmission = $attempt->hasExpired();

        if ($lateSubmission && $quiz->time_expiry_policy === TimeExpiryPolicy::AutoAbandon) {
            $attempt->forceFill([
                'status' => AttemptStatus::Expired,
                'submitted_at' => now(),
                'result' => AttemptResult::Fail,
                'earned_points' => 0,
                'percent' => 0,
            ])->save();

            QuizAttemptSubmitted::dispatch($attempt);

            return $attempt->refresh();
        }

        /** @var Collection<int, Question> $questions */
        $questions = $quiz->questions->keyBy('id');
        $answers = $attempt->answers->keyBy('question_id');

        $earned = 0.0;
        $needsReview = false;

        DB::transaction(function () use (
            $attempt, $quiz, $questions, $answers, &$earned, &$needsReview
        ): void {
            foreach ($attempt->question_order ?? [] as $questionId) {
                $question = $questions->get($questionId);

                if ($question === null) {
                    continue;
                }

                $points = $quiz->pointsFor($question);
                $given = $answers->get($questionId);

                $result = $this->grader->grade(
                    $question,
                    $given?->answer,
                    $points,
                    $quiz->negative_marking,
                );

                if ($result->isCorrect === null) {
                    $needsReview = true;
                } else {
                    $earned += $result->pointsEarned;
                }

                QuizAttemptAnswer::updateOrCreate(
                    ['attempt_id' => $attempt->id, 'question_id' => $questionId],
                    [
                        'question_type' => $question->type,
                        'answer' => $given?->answer,
                        'points_possible' => $points,
                        'points_earned' => $result->isCorrect === null ? 0 : $result->pointsEarned,
                        'is_correct' => $result->isCorrect,
                    ],
                );
            }

            // Negative marking can drive a raw total below zero; a negative
            // course grade helps nobody.
            $earned = max(0.0, $earned);
            $total = (float) $attempt->total_points;
            $percent = $total > 0 ? round(($earned / $total) * 100, 2) : 0.0;

            $attempt->forceFill([
                'status' => $needsReview ? AttemptStatus::AwaitingReview : AttemptStatus::Graded,
                'submitted_at' => now(),
                'graded_at' => $needsReview ? null : now(),
                'earned_points' => $earned,
                'percent' => $percent,
                'result' => $needsReview
                    ? AttemptResult::Pending
                    : ($percent >= $quiz->passing_score_percent ? AttemptResult::Pass : AttemptResult::Fail),
            ])->save();
        });

        $attempt->refresh();

        QuizAttemptSubmitted::dispatch($attempt);

        if (! $needsReview) {
            $this->finalise($attempt);
        } else {
            // Submitting counts as engaging with the item even while the score
            // is pending; the learner should not be stuck.
            $this->markItemComplete($attempt);
        }

        return $attempt;
    }

    public function finalise(QuizAttempt $attempt): void
    {
        $passed = $attempt->result === AttemptResult::Pass;

        QuizAttemptGraded::dispatch($attempt, $passed);

        $this->markItemComplete($attempt);
    }

    private function markItemComplete(QuizAttempt $attempt): void
    {
        $attempt->loadMissing(['enrollment', 'item']);

        if ($attempt->enrollment !== null && $attempt->item !== null) {
            $this->progress->complete($attempt->enrollment, $attempt->item);
        }
    }
}
