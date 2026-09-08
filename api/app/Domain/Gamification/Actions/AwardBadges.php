<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Actions;

use App\Domain\Gamification\Events\BadgeAwarded;
use App\Domain\Gamification\Models\Badge;
use App\Domain\Gamification\Models\GamificationProfile;
use App\Domain\Gamification\Models\UserBadge;
use App\Domain\Gamification\Support\BadgeCriteria;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Gives somebody every badge they have now earned and do not yet hold.
 *
 * Evaluated from scratch each time rather than incrementally. That sounds
 * wasteful and is the point: a badge added months later is earned by everybody
 * who already qualifies the next time they do anything, with no backfill job
 * and no "why did I not get this?" support thread.
 *
 * ONLY THE UNHELD ONES ARE CHECKED, so a learner with every badge costs one
 * query and no evaluation at all.
 *
 * Awards are caught, not checked. Two triggers landing together — a lesson
 * that also completes a course — would both find the badge unheld and both
 * insert; the unique index refuses the second and this treats that as the
 * answer rather than an error.
 *
 * @return Collection<int, Badge> what was newly awarded
 */
final class AwardBadges
{
    public function __construct(private readonly BadgeCriteria $criteria) {}

    /** @return Collection<int, Badge> */
    public function handle(int $userId): Collection
    {
        $profile = GamificationProfile::query()->find($userId);

        if ($profile === null) {
            // Nobody with no profile has earned anything.
            return new Collection;
        }

        $held = UserBadge::query()->where('user_id', $userId)->pluck('badge_id')->all();

        $candidates = Badge::query()
            ->active()
            ->whereNotIn('id', $held)
            ->get();

        /** @var Collection<int, Badge> $awarded */
        $awarded = new Collection;

        foreach ($candidates as $badge) {
            if (! $this->criteria->met($badge->criteria, $profile)) {
                continue;
            }

            try {
                UserBadge::create([
                    'user_id' => $userId,
                    'badge_id' => $badge->id,
                    'awarded_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another trigger got there first. Not an error.
                continue;
            }

            BadgeAwarded::dispatch($userId, $badge);
            $awarded->push($badge);
        }

        return $awarded;
    }
}
