<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Enums;

/**
 * What a leaderboard is a leaderboard OF.
 *
 * `Course` matters more than `Global`: a board of everybody in the academy is
 * won by whoever studies most, which is a fact about their free time. A board
 * of one course is a comparison between people doing the same thing.
 */
enum LeaderboardScope: string
{
    case Global = 'global';
    case Course = 'course';

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Academy',
            self::Course => 'Course',
        };
    }
}
