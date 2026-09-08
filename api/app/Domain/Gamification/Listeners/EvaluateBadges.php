<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Listeners;

use App\Domain\Gamification\Actions\AwardBadges;
use App\Domain\Gamification\Events\PointsAwarded;
use App\Domain\Gamification\Events\StreakExtended;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Badges are re-evaluated whenever a learner's standing moves.
 *
 * Two triggers, because there are two ways to move: earning points, and
 * showing up on a new day. A streak badge would otherwise wait for the next
 * lesson to be noticed, which means somebody hits thirty days and is told
 * about it on day thirty-one.
 *
 * It listens to gamification's OWN events rather than to the domain events
 * that caused them, so it runs exactly as often as something changed — a rule
 * refused by its dedupe key or its daily cap fires nothing and evaluates
 * nothing.
 */
final class EvaluateBadges implements ShouldQueue
{
    public function __construct(private readonly AwardBadges $award) {}

    public function points(PointsAwarded $event): void
    {
        $this->award->handle($event->transaction->user_id);
    }

    public function streak(StreakExtended $event): void
    {
        $this->award->handle($event->userId);
    }
}
