<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Actions;

use App\Domain\Gamification\Events\StreakExtended;
use App\Domain\Gamification\Models\GamificationProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records that a learner did something today.
 *
 * A UTC DAY, for the same reason the analytics rollups use one: an academy has
 * no timezone of its own, and a streak keyed on a shifting local day could not
 * be recomputed deterministically. It rolls over at an hour that is midnight
 * for almost nobody, which is a real wart and a smaller one than a counter
 * nobody can rebuild.
 *
 * IDEMPOTENT WITHIN A DAY. Twenty lessons on Tuesday is one day of a streak,
 * so the second call and the twentieth do nothing at all.
 */
final class TouchStreak
{
    public function handle(int $userId, ?CarbonImmutable $today = null): GamificationProfile
    {
        $today ??= CarbonImmutable::now('UTC')->startOfDay();

        return DB::transaction(function () use ($userId, $today): GamificationProfile {
            GamificationProfile::query()->insertOrIgnore([
                'user_id' => $userId,
                'points_total' => 0,
                'current_streak_days' => 0,
                'longest_streak_days' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /** @var GamificationProfile $profile */
            $profile = GamificationProfile::query()->lockForUpdate()->findOrFail($userId);

            $last = $profile->last_active_date;

            if ($last !== null && $last->isSameDay($today)) {
                // Already counted today. Twenty lessons is still one day.
                return $profile;
            }

            /*
             * Consecutive means YESTERDAY, and nothing else. A "grace day"
             * that quietly forgives a gap makes the number a lie — somebody
             * showing a 40-day streak they did not earn stops believing any
             * of it.
             */
            $continued = $last !== null && $last->isSameDay($today->subDay());
            $current = $continued ? $profile->current_streak_days + 1 : 1;

            $profile->forceFill([
                'current_streak_days' => $current,
                'longest_streak_days' => max($current, $profile->longest_streak_days),
                'last_active_date' => $today->toDateString(),
            ])->save();

            StreakExtended::dispatch($userId, $current, $continued);

            return $profile;
        });
    }
}
