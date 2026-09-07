<?php

declare(strict_types=1);

namespace App\Domain\Progress\Enums;

enum ItemProgressStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function isComplete(): bool
    {
        return $this === self::Completed;
    }
}
