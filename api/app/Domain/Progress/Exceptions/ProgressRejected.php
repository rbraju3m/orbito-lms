<?php

declare(strict_types=1);

namespace App\Domain\Progress\Exceptions;

use App\Domain\Curriculum\Enums\ItemType;
use App\Support\Exceptions\DomainException;

final class ProgressRejected extends DomainException
{
    public static function notSelfMarkable(ItemType $type): self
    {
        return new self(
            match ($type) {
                ItemType::Quiz => 'Submit an attempt to complete this quiz.',
                default => 'This item cannot be marked complete by hand.',
            }
        );
    }

    public static function retakeNotAllowed(): self
    {
        return new self('This course cannot be retaken.');
    }

    public static function resetNotAllowed(): self
    {
        return new self('Progress in this course cannot be reset.');
    }

    public function errorCode(): string
    {
        return 'progress_rejected';
    }

    public function status(): int
    {
        return 409;
    }
}
