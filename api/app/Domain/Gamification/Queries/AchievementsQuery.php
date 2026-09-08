<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Queries;

use App\Domain\Gamification\Models\Badge;
use App\Domain\Gamification\Models\GamificationProfile;
use App\Domain\Gamification\Models\PointTransaction;
use App\Domain\Gamification\Models\UserBadge;
use Illuminate\Support\Collection;

/**
 * Everything one learner has to show for themselves.
 *
 * The badge WALL includes what they have NOT earned, greyed out with its
 * requirement showing. A shelf of only the badges you already hold is a
 * trophy cabinet; a shelf with the next one visible is a reason to come back,
 * and hiding the requirement makes it a lottery.
 */
final class AchievementsQuery
{
    /**
     * @return array{
     *     profile: GamificationProfile,
     *     badges: Collection<int, Badge>,
     *     held: array<int, string>,
     *     recent: Collection<int, PointTransaction>
     * }
     */
    public function forUser(int $userId): array
    {
        $profile = GamificationProfile::query()->find($userId) ?? new GamificationProfile([
            // Somebody who has done nothing gets zeros, not a 404. An empty
            // profile is a real state and the screen has to render it.
            'user_id' => $userId,
            'points_total' => 0,
            'current_streak_days' => 0,
            'longest_streak_days' => 0,
            'is_ranked' => true,
        ]);

        /** @var array<int, string> $held badge id => awarded_at */
        $held = UserBadge::query()
            ->where('user_id', $userId)
            ->pluck('awarded_at', 'badge_id')
            ->map(fn (mixed $at): string => (string) $at)
            ->all();

        $badges = Badge::query()
            ->active()
            ->with('icon')
            ->get()
            // Held first, then by how hard they are — a wall sorted purely by
            // tier buries what somebody just earned.
            ->sortBy([
                fn (Badge $a, Badge $b) => (isset($held[$b->id]) ? 1 : 0) <=> (isset($held[$a->id]) ? 1 : 0),
                fn (Badge $a, Badge $b) => $a->tier->weight() <=> $b->tier->weight(),
                fn (Badge $a, Badge $b) => strcmp($a->name, $b->name),
            ])
            ->values();

        return [
            'profile' => $profile,
            'badges' => $badges,
            'held' => $held,
            'recent' => PointTransaction::query()
                ->where('user_id', $userId)
                ->orderByDesc('awarded_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get(),
        ];
    }
}
