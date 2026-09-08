<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A learner was active on a new day.
 *
 * Fires on the first activity of a UTC day and not again, so a listener may
 * treat it as "a day happened". `$continued` is false when the streak restarted
 * — which is the same event, because starting again is also worth knowing.
 *
 * Carries scalars: by the time anything reads this the profile row has already
 * moved on, and handing over a model invites a listener to re-read it.
 */
final class StreakExtended
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly int $currentDays,
        public readonly bool $continued,
    ) {}
}
