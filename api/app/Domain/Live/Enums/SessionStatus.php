<?php

declare(strict_types=1);

namespace App\Domain\Live\Enums;

/**
 * Where a session is in its life.
 *
 * `Live` and `Ended` are DERIVED from the clock on every read, never swept —
 * the same reasoning as drip, sale prices and published announcements. A
 * status that needed a cron to become true would be wrong for however long
 * the cron was late, and it is wrong exactly when somebody is trying to join.
 *
 * `Cancelled` is the only one set by hand, because it is the only one the
 * clock cannot know.
 */
enum SessionStatus: string
{
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Live => 'Live now',
            self::Ended => 'Ended',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether a learner may follow the join link.
     *
     * `Live` ONLY — and `currentStatus()` opens that window fifteen minutes
     * early, which is what makes arriving early work. Counting `Scheduled` as
     * joinable would put the URL on the page a week ahead, and a link rendered
     * a week ahead is a link that ends up in a group chat.
     */
    public function isJoinable(): bool
    {
        return $this === self::Live;
    }
}
