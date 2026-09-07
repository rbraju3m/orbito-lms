<?php

declare(strict_types=1);

namespace App\Http\Resources\Assessment;

use App\Domain\Assessment\Enums\ShowAnswersAfter;
use App\Domain\Assessment\Models\QuizAttempt;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin QuizAttempt
 */
final class AttemptResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $finished = ! $this->status->isOpen();

        return [
            'id' => $this->uuid,
            'attempt_number' => $this->attempt_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'started_at' => $this->started_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            // The authoritative countdown. A client clock is display only, and
            // the server re-checks the deadline on every write.
            'seconds_remaining' => $this->secondsRemaining(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),

            // Scores exist only once the attempt is over.
            'total_points' => $this->when($finished, fn () => (float) $this->total_points),
            'earned_points' => $this->when($finished, fn () => (float) $this->earned_points),
            'percent' => $this->when($finished, fn () => (float) $this->percent),
            'result' => $this->when($finished, fn () => $this->result?->value),
            'passed' => $this->when($finished, fn () => $this->result?->value === 'pass'),

            'quiz' => $this->whenLoaded('quiz', fn () => [
                'passing_score_percent' => $this->quiz->passing_score_percent,
                'feedback_mode' => $this->quiz->feedback_mode->value,
                'questions_per_page' => $this->quiz->questions_per_page,
                'hide_question_numbers' => $this->quiz->hide_question_numbers,
                'allow_previous_button' => $this->quiz->allow_previous_button,
                'show_correct_answers' => $this->mayRevealAnswers(),
            ]),
        ];
    }

    /**
     * Whether the correct answers may be revealed yet. Decided here, on the
     * server — a client cannot ask for them earlier (ADR-06).
     */
    public function mayRevealAnswers(): bool
    {
        if ($this->status->isOpen()) {
            return false;
        }

        return match ($this->quiz->show_correct_answers_after) {
            ShowAnswersAfter::Never => false,
            ShowAnswersAfter::Submission => true,
            ShowAnswersAfter::Pass => $this->result?->value === 'pass',
        };
    }
}
