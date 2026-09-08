<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Enums;

/**
 * How hard a badge was to get.
 *
 * Three tiers, not seven. A ladder long enough to be interesting is a ladder
 * where the middle rungs mean nothing, and the tier exists to sort a wall of
 * badges rather than to be a second points system.
 */
enum BadgeTier: string
{
    case Bronze = 'bronze';
    case Silver = 'silver';
    case Gold = 'gold';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** For sorting a shelf: the rare ones first. */
    public function weight(): int
    {
        return match ($this) {
            self::Gold => 3,
            self::Silver => 2,
            self::Bronze => 1,
        };
    }
}
