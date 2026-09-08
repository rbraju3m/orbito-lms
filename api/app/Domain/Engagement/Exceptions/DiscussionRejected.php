<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use App\Support\Exceptions\DomainException;

final class DiscussionRejected extends DomainException
{
    public static function notEnabled(): self
    {
        return new self('Questions are turned off for this course.');
    }

    public static function notAnswerable(): self
    {
        return new self('Only a question can have an accepted answer.');
    }

    public static function replyNotInThread(): self
    {
        return new self('That reply does not belong to this question.');
    }

    public static function threadClosed(): self
    {
        return new self('This thread is closed.');
    }

    public function errorCode(): string
    {
        return 'discussion_rejected';
    }

    public function status(): int
    {
        return 409;
    }
}
