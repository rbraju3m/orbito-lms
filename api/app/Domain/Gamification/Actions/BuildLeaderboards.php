<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Actions;

use App\Domain\Gamification\Enums\LeaderboardPeriod;
use App\Domain\Gamification\Enums\LeaderboardScope;
use App\Domain\Gamification\Models\LeaderboardSnapshot;
use App\Domain\Gamification\Models\PointTransaction;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Computes and freezes every board.
 *
 * IDEMPOTENT, like the analytics rollups and for the same reason: a failed
 * run's recovery has to be "run it again". The unique key on
 * (scope, scope_id, period, period_start) is what makes that true.
 *
 * A board is built from the LEDGER, summed over its window — not from
 * `points_total`, which is a lifetime figure and would make every period
 * board identical to the all-time one.
 *
 * Names are resolved once, here, and frozen into the JSON. They are central
 * and this runs on the tenant connection, so the ids are collected first and
 * looked up in one `whereIn` (§ Multi-tenancy).
 */
final class BuildLeaderboards
{
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $size = (int) config('gamification.leaderboard.size', 50);
        $built = 0;

        foreach (LeaderboardPeriod::cases() as $period) {
            $built += $this->global($period, $now, $size);
            $built += $this->perCourse($period, $now, $size);
        }

        return $built;
    }

    private function global(LeaderboardPeriod $period, CarbonImmutable $now, int $size): int
    {
        $rows = $this->totals($period, $now, null, $size);

        $this->store(LeaderboardScope::Global, null, $period, $now, $rows);

        return 1;
    }

    private function perCourse(LeaderboardPeriod $period, CarbonImmutable $now, int $size): int
    {
        $courseIds = PointTransaction::query()
            ->whereNotNull('course_id')
            ->where('awarded_at', '>=', $period->startFor($now))
            ->where('awarded_at', '<', $period->endFor($now))
            ->distinct()
            ->pluck('course_id');

        foreach ($courseIds as $courseId) {
            $this->store(
                LeaderboardScope::Course,
                (int) $courseId,
                $period,
                $now,
                $this->totals($period, $now, (int) $courseId, $size),
            );
        }

        return $courseIds->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function totals(LeaderboardPeriod $period, CarbonImmutable $now, ?int $courseId, int $size): array
    {
        $rows = PointTransaction::query()
            ->join(
                'gamification_profiles',
                'gamification_profiles.user_id',
                '=',
                'point_transactions.user_id',
            )
            /*
             * Opted-out learners are excluded at the SOURCE, not filtered out
             * of the rendered board. A name removed after ranking would leave
             * a visible gap at position 4, which tells everybody exactly who
             * opted out.
             */
            ->where('gamification_profiles.is_ranked', true)
            ->where('point_transactions.awarded_at', '>=', $period->startFor($now))
            ->where('point_transactions.awarded_at', '<', $period->endFor($now))
            ->when($courseId !== null, fn ($query) => $query->where('point_transactions.course_id', $courseId))
            ->groupBy('point_transactions.user_id')
            ->selectRaw('point_transactions.user_id, SUM(points) as points')
            ->orderByDesc('points')
            // A tiebreak, or two people on equal points swap places on every
            // rebuild and the board looks broken.
            ->orderBy('point_transactions.user_id')
            ->limit($size)
            ->toBase()
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        /** @var Collection<int, stdClass> $rows */
        $names = User::query()
            ->whereIn('id', $rows->map(fn (stdClass $row): int => (int) $row->user_id)->all())
            ->pluck('name', 'id');

        $rank = 0;

        return $rows->map(function (stdClass $row) use ($names, &$rank): array {
            $rank++;

            return [
                'rank' => $rank,
                'user_id' => (int) $row->user_id,
                // Frozen at build time. A rename appears on the next build.
                'name' => $names[$row->user_id] ?? 'Former member',
                'points' => (int) $row->points,
            ];
        })->all();
    }

    /** @param  list<array<string, mixed>>  $entries */
    private function store(
        LeaderboardScope $scope,
        ?int $scopeId,
        LeaderboardPeriod $period,
        CarbonImmutable $now,
        array $entries,
    ): void {
        LeaderboardSnapshot::query()->updateOrCreate(
            [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'period' => $period,
                'period_start' => $period->startFor($now)->toDateString(),
            ],
            ['entries' => $entries, 'computed_at' => $now],
        );
    }
}
