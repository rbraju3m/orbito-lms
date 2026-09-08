<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Events;

use App\Domain\Gamification\Models\Badge;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody earned a badge, for the first and only time.
 *
 * Named in EVENTS.md §4 as the Phase 14 event. Fires once per (learner, badge)
 * because the unique index makes a second award impossible — so a listener may
 * send mail without checking whether it already did.
 */
final class BadgeAwarded
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly Badge $badge,
    ) {}
}
