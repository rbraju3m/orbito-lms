<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Exceptions\AttemptRejected;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuizAttempt;
use App\Domain\Assessment\Models\QuizAttemptAnswer;

/**
 * Autosave for one answer.
 *
 * Deliberately does NOT grade: grading here would leak whether the answer was
 * right while the attempt is still open (ADR-06). The only exception is the
 * `reveal`/`retry` feedback modes, which grade explicitly through
 * GradeSingleAnswer.
 */
final class SaveAttemptAnswer
{
    /** @param  array<string, mixed>  $answer */
    public function handle(QuizAttempt $attempt, Question $question, array $answer): QuizAttemptAnswer
    {
        if (! $attempt->status->isOpen()) {
            throw AttemptRejected::alreadySubmitted();
        }

        // A late save is refused outright rather than quietly accepted and then
        // discarded at submission.
        if ($attempt->hasExpired()) {
            throw AttemptRejected::expired();
        }

        $attempt->loadMissing('quiz');

        return QuizAttemptAnswer::updateOrCreate(
            ['attempt_id' => $attempt->id, 'question_id' => $question->id],
            [
                'question_type' => $question->type,
                'answer' => $answer,
                'points_possible' => $attempt->quiz->pointsFor($question),
                // Scores stay zero until submission; nothing here tells the
                // learner whether they were right.
                'points_earned' => 0,
                'is_correct' => null,
            ],
        );
    }
}
