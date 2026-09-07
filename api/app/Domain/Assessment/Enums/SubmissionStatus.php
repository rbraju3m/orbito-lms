<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * There is deliberately no `draft`. A row exists because the learner handed
 * something in; anything before that is text in a box on their own machine,
 * and inventing a server-side draft would mean a second "is this really
 * submitted?" question on every read path.
 */
enum SubmissionStatus: string
{
    /** Handed in, waiting for a person. The assignment half of the queue. */
    case Submitted = 'submitted';
    case Graded = 'graded';
    /** Handed back for another go. Does not consume an attempt. */
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Awaiting review',
            self::Graded => 'Graded',
            self::Returned => 'Returned for another attempt',
        };
    }

    public function isAwaitingReview(): bool
    {
        return $this === self::Submitted;
    }

    /** Whether this submission used up one of the learner's attempts. */
    public function consumesAttempt(): bool
    {
        return $this !== self::Returned;
    }
}
