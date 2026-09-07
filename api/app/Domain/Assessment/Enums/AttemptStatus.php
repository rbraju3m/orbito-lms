<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

enum AttemptStatus: string
{
    case InProgress = 'in_progress';
    /** Submitted and awaiting a human for at least one answer. */
    case AwaitingReview = 'awaiting_review';
    case Graded = 'graded';
    case Abandoned = 'abandoned';
    /** Ran out of time under an auto-abandon policy. */
    case Expired = 'expired';

    public function isOpen(): bool
    {
        return $this === self::InProgress;
    }

    public function isFinished(): bool
    {
        return $this === self::Graded || $this === self::Abandoned || $this === self::Expired;
    }

    public function countsAsCompleted(): bool
    {
        return $this === self::Graded || $this === self::AwaitingReview;
    }

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            self::AwaitingReview => 'Awaiting review',
            self::Graded => 'Graded',
            self::Abandoned => 'Abandoned',
            self::Expired => 'Expired',
        };
    }
}
