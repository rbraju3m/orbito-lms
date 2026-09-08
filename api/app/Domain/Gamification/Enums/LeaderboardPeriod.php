<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Enums;

use Carbon\CarbonImmutable;

/**
 * The windows a leaderboard is computed over.
 *
 * `AllTime` is here and it is the one that makes leaderboards unkind: a board
 * nobody new can ever appear on stops being a competition and becomes a list
 * of people who joined early. The weekly and monthly boards are the ones the
 * UI leads with; all-time is available and deliberately not the default.
 */
enum LeaderboardPeriod: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case AllTime = 'all_time';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'This week',
            self::Monthly => 'This month',
            self::AllTime => 'All time',
        };
    }

    /** The UTC day a period containing `$moment` starts on. */
    public function startFor(CarbonImmutable $moment): CarbonImmutable
    {
        return match ($this) {
            // Monday, because a week that starts on Sunday puts a weekend in
            // the middle of it and the board resets mid-effort.
            self::Weekly => $moment->startOfWeek(CarbonImmutable::MONDAY),
            self::Monthly => $moment->startOfMonth(),
            // A fixed epoch, so the unique key has something to hold.
            self::AllTime => CarbonImmutable::parse('1970-01-01', 'UTC'),
        };
    }

    public function endFor(CarbonImmutable $moment): CarbonImmutable
    {
        return match ($this) {
            self::Weekly => $this->startFor($moment)->addWeek(),
            self::Monthly => $this->startFor($moment)->addMonth(),
            self::AllTime => $moment->addDay()->startOfDay(),
        };
    }
}
