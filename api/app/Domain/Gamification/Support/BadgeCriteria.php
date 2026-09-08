<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Support;

use App\Domain\Gamification\Enums\TriggerEvent;
use App\Domain\Gamification\Models\GamificationProfile;
use App\Domain\Gamification\Models\PointTransaction;

/**
 * Whether somebody has earned a badge.
 *
 * A CLOSED SET OF FIVE SHAPES. There is deliberately no expression language:
 * one that can express anything is one nobody can debug when a learner asks
 * why they did not get a badge, and it would be an evaluator running JSON that
 * an academy can edit through the API — which is a sandbox escape somebody
 * eventually writes.
 *
 * EVERYTHING IS COUNTED FROM GAMIFICATION'S OWN DATA — the ledger and the
 * profile — never from `item_progress` or `enrollments`. Two consequences,
 * both deliberate and both worth knowing:
 *
 *  - A learner who finished fifty lessons before this phase shipped has no
 *    ledger rows and no badge. We started counting when we started counting;
 *    inventing history would award "First step" to somebody who has not
 *    opened the site in a year.
 *  - Deactivating a rule freezes the badges that depend on it, because the
 *    ledger stops recording. That is the academy's choice showing through,
 *    not a bug.
 *
 * A count is DISTINCT by source, so the ledger's own idempotency carries into
 * the badges: fifty rows for one lesson could never happen, and if they did
 * they would still be one lesson.
 */
final class BadgeCriteria
{
    /** @return list<string> */
    public static function types(): array
    {
        return ['points_total', 'streak_days', 'lessons_completed', 'courses_completed', 'answers_accepted'];
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    public function met(array $criteria, GamificationProfile $profile): bool
    {
        $threshold = (int) ($criteria['threshold'] ?? 0);

        if ($threshold < 1) {
            // A badge with no threshold would be earned by everybody the
            // moment it was created.
            return false;
        }

        return match ((string) ($criteria['type'] ?? '')) {
            'points_total' => $profile->points_total >= $threshold,

            /*
             * LONGEST, not current. A badge is never taken back, so awarding
             * on the current streak and revoking when it breaks would be
             * worse than not awarding at all — and "you once learned for
             * thirty days running" stays true afterwards.
             */
            'streak_days' => $profile->longest_streak_days >= $threshold,

            'lessons_completed' => $this->distinctSources($profile->user_id, TriggerEvent::ItemCompleted) >= $threshold,
            'courses_completed' => $this->distinctSources($profile->user_id, TriggerEvent::CourseCompleted) >= $threshold,
            'answers_accepted' => $this->distinctSources($profile->user_id, TriggerEvent::DiscussionAnswerAccepted) >= $threshold,

            // An unknown type awards nothing. A typo in an academy's badge
            // must not hand it to everybody.
            default => false,
        };
    }

    private function distinctSources(int $userId, TriggerEvent $trigger): int
    {
        return (int) PointTransaction::query()
            ->join('gamification_rules', 'gamification_rules.id', '=', 'point_transactions.rule_id')
            ->where('point_transactions.user_id', $userId)
            ->where('gamification_rules.event_name', $trigger->value)
            ->distinct()
            ->count('point_transactions.source_id');
    }
}
