<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

enum DiscussionType: string
{
    case Question = 'question';
    case Comment = 'comment';

    /**
     * Only a question can be answered.
     *
     * Accepting an answer on a comment is meaningless, and letting it happen
     * would put "resolved" badges on threads nobody asked anything in.
     */
    public function isAnswerable(): bool
    {
        return $this === self::Question;
    }

    public function label(): string
    {
        return match ($this) {
            self::Question => 'Question',
            self::Comment => 'Comment',
        };
    }
}
