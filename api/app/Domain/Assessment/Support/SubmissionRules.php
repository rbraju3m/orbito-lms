<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Support;

use App\Domain\Assessment\Models\Assignment;
use App\Domain\Assessment\Models\AssignmentSubmission;
use Illuminate\Support\Collection;

/**
 * "May this learner hand something in, and on what terms?"
 *
 * One class, rendered by the submission form and enforced by the submit
 * Action, so the button and the server cannot drift — the same shape as
 * `PublishChecklist` in Phase 4.
 *
 * @phpstan-type RulesArray array{
 *     can_submit: bool,
 *     reason: string|null,
 *     attempts_used: int,
 *     attempts_allowed: int|null,
 *     attempts_left: int|null,
 *     is_past_due: bool,
 *     will_be_late: bool,
 *     late_penalty_percent: int,
 * }
 */
final class SubmissionRules
{
    /** @param  Collection<int, AssignmentSubmission>  $previous */
    public function __construct(
        private readonly Assignment $assignment,
        private readonly Collection $previous,
    ) {}

    /**
     * A returned submission does not count: handing work back is the
     * instructor saying "this one does not count, try again", and charging the
     * learner an attempt for it would make the gesture punitive.
     */
    public function attemptsUsed(): int
    {
        return $this->previous
            ->filter(fn (AssignmentSubmission $s) => $s->status->consumesAttempt())
            ->count();
    }

    public function attemptsLeft(): ?int
    {
        if ($this->assignment->max_attempts === null) {
            return null;
        }

        return max(0, $this->assignment->max_attempts - $this->attemptsUsed());
    }

    public function isPastDue(): bool
    {
        return $this->assignment->isPastDue();
    }

    /** The reason a submission would be refused, or null if it would be taken. */
    public function refusal(): ?string
    {
        if ($this->attemptsLeft() === 0) {
            return 'no_attempts_left';
        }

        if ($this->isPastDue() && ! $this->assignment->late_policy->allowsLate()) {
            return 'past_due';
        }

        return null;
    }

    public function canSubmit(): bool
    {
        return $this->refusal() === null;
    }

    /** @return RulesArray */
    public function toArray(): array
    {
        return [
            'can_submit' => $this->canSubmit(),
            'reason' => $this->refusal(),
            'attempts_used' => $this->attemptsUsed(),
            'attempts_allowed' => $this->assignment->max_attempts,
            'attempts_left' => $this->attemptsLeft(),
            'is_past_due' => $this->isPastDue(),
            'will_be_late' => $this->isPastDue() && $this->assignment->late_policy->allowsLate(),
            'late_penalty_percent' => $this->assignment->late_penalty_percent,
        ];
    }

    /**
     * The next attempt number. Always increments, including over a returned
     * attempt, so `(assignment, user, attempt_number)` stays unique and the
     * history reads in the order it happened.
     */
    public function nextAttemptNumber(): int
    {
        return ((int) $this->previous->max('attempt_number')) + 1;
    }
}
