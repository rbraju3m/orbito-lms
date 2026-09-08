<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Actions;

use App\Domain\Gamification\Data\TriggerContext;
use App\Domain\Gamification\Models\GamificationRule;
use App\Domain\Gamification\Models\PointTransaction;
use App\Domain\Gamification\Support\RuleConditions;
use Illuminate\Support\Collection;

/**
 * The rule engine.
 *
 * One thing happened; every active rule watching that trigger gets a look at
 * it. Rules are DATA — an academy retunes points or switches one off without a
 * deploy — and this is the only place they are read.
 *
 * It evaluates against the TriggerContext and never re-reads the model. By the
 * time a queued listener runs, the row may have changed: a rule that
 * re-queried would award on the state it finds rather than the state that
 * earned it, and a learner who fixed a typo in their review would be paid for
 * a different review than the one that fired.
 *
 * The streak is touched once per trigger regardless of whether any rule paid
 * out — showing up is the thing a streak measures, not earning.
 *
 * @see AwardPoints for the idempotency and the locking
 */
final class EvaluateTrigger
{
    public function __construct(
        private readonly RuleConditions $conditions,
        private readonly AwardPoints $award,
        private readonly TouchStreak $streak,
    ) {}

    /** @return Collection<int, PointTransaction> what was actually awarded */
    public function handle(TriggerContext $context): Collection
    {
        $this->streak->handle($context->userId);

        /** @var Collection<int, PointTransaction> $awarded */
        $awarded = new Collection;

        $rules = GamificationRule::query()->watching($context->trigger)->get();

        foreach ($rules as $rule) {
            if (! $this->conditions->pass($rule->conditions, $context)) {
                continue;
            }

            $transaction = $this->award->handle(
                $context->userId,
                $rule,
                [
                    'source_type' => $context->sourceType,
                    'source_id' => $context->sourceId,
                    'reason' => $rule->name,
                    'course_id' => $context->courseId,
                ],
                // A repeatable rule passes no key, so the unique index — which
                // allows any number of NULLs — lets it through every time.
                $context->trigger->isOncePerSource() ? $context->dedupeKey($rule->key) : null,
            );

            if ($transaction !== null) {
                $awarded->push($transaction);
            }
        }

        return $awarded;
    }
}
