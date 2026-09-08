<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Actions;

use App\Domain\Gamification\Events\PointsAwarded;
use App\Domain\Gamification\Models\GamificationProfile;
use App\Domain\Gamification\Models\GamificationRule;
use App\Domain\Gamification\Models\PointTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Credits one learner, once.
 *
 * COUNT AND INSERT IN ONE TRANSACTION, behind a lock on the learner's profile
 * row (§15). The daily cap and the running balance are both read-then-written,
 * and two awards arriving together — a learner finishing a lesson that also
 * completes the course — would otherwise both see the same balance and both
 * pass the same cap.
 *
 * The dedupe key is the real guarantee. A learner who un-ticks and re-ticks a
 * lesson fires `ItemCompleted` again; the unique index refuses the second row,
 * and this catches that refusal rather than checking first. A check-then-
 * insert loses exactly the race a double click creates.
 */
final class AwardPoints
{
    /**
     * @param  array<string, mixed>  $attributes  source_type, source_id, reason, course_id
     * @return PointTransaction|null null when nothing was awarded
     */
    public function handle(
        int $userId,
        GamificationRule $rule,
        array $attributes = [],
        ?string $dedupeKey = null,
    ): ?PointTransaction {
        return DB::transaction(function () use ($userId, $rule, $attributes, $dedupeKey): ?PointTransaction {
            $profile = $this->lockedProfile($userId);

            if (! $this->withinCap($userId, $rule) || ! $this->pastCooldown($userId, $rule)) {
                return null;
            }

            $balance = $profile->points_total + $rule->points;

            try {
                $transaction = PointTransaction::create([
                    'user_id' => $userId,
                    'rule_id' => $rule->id,
                    'points' => $rule->points,
                    'balance_after' => $balance,
                    'source_type' => $attributes['source_type'] ?? null,
                    'source_id' => $attributes['source_id'] ?? null,
                    'reason' => $attributes['reason'] ?? $rule->name,
                    'course_id' => $attributes['course_id'] ?? null,
                    'dedupe_key' => $dedupeKey,
                    'awarded_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                /*
                 * Already paid for this exact thing. Not an error and not
                 * worth reporting — it is the constraint doing its job on a
                 * learner who clicked twice.
                 */
                return null;
            }

            $profile->forceFill(['points_total' => $balance])->save();

            PointsAwarded::dispatch($transaction, $balance);

            return $transaction;
        });
    }

    /**
     * The profile row, created if missing, locked either way.
     *
     * `insertOrIgnore` rather than `firstOrCreate`: two concurrent awards for
     * a learner with no profile yet would both find nothing and both insert,
     * and one would throw on the primary key.
     */
    private function lockedProfile(int $userId): GamificationProfile
    {
        GamificationProfile::query()->insertOrIgnore([
            'user_id' => $userId,
            'points_total' => 0,
            'current_streak_days' => 0,
            'longest_streak_days' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return GamificationProfile::query()->lockForUpdate()->findOrFail($userId);
    }

    /**
     * A ceiling on a repeatable rule.
     *
     * Counted over a UTC day, like everything else dated in this system.
     */
    private function withinCap(int $userId, GamificationRule $rule): bool
    {
        if ($rule->max_per_day === null) {
            return true;
        }

        $today = now()->startOfDay();

        return PointTransaction::query()
            ->where('user_id', $userId)
            ->where('rule_id', $rule->id)
            ->where('awarded_at', '>=', $today)
            ->where('awarded_at', '<', $today->copy()->addDay())
            ->count() < $rule->max_per_day;
    }

    /**
     * How long since this rule last paid this learner.
     *
     * The answer to re-grading: an assignment handed back and marked again is
     * a real second event, but a grader correcting a typo an hour later is
     * not a second achievement.
     */
    private function pastCooldown(int $userId, GamificationRule $rule): bool
    {
        if ($rule->cooldown_seconds === 0) {
            return true;
        }

        return ! PointTransaction::query()
            ->where('user_id', $userId)
            ->where('rule_id', $rule->id)
            ->where('awarded_at', '>', now()->subSeconds($rule->cooldown_seconds))
            ->exists();
    }
}
