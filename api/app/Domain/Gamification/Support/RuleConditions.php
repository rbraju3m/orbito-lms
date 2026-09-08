<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Support;

use App\Domain\Gamification\Data\TriggerContext;

/**
 * Whether a rule's conditions hold for what just happened.
 *
 * A CLOSED set of operators, checked against the trigger's payload. There is
 * deliberately no expression language: one that can express anything is one
 * nobody can debug when a learner asks why they got no points, and it is an
 * evaluator taking JSON somebody can edit through the API — which is a
 * sandbox escape waiting to be written.
 *
 * An unknown key REFUSES the award rather than ignoring it. A typo in an
 * academy's rule must not silently pay everybody.
 */
final class RuleConditions
{
    /** @param  array<string, mixed>|null  $conditions */
    public function pass(?array $conditions, TriggerContext $context): bool
    {
        foreach ($conditions ?? [] as $key => $expected) {
            if (! $this->check((string) $key, $expected, $context)) {
                return false;
            }
        }

        return true;
    }

    private function check(string $key, mixed $expected, TriggerContext $context): bool
    {
        return match ($key) {
            // Numeric floors, for a score or a count in the payload.
            'min_percent' => (float) ($context->payload['percent'] ?? 0) >= (float) $expected,
            'min_points' => (float) ($context->payload['points_earned'] ?? 0) >= (float) $expected,

            // Exact matches on a flag the event settled.
            'passed' => (bool) ($context->payload['passed'] ?? false) === (bool) $expected,
            'is_late' => (bool) ($context->payload['is_late'] ?? false) === (bool) $expected,

            // Narrow a rule to one course — an academy running a campaign.
            'course_id' => $context->courseId === (int) $expected,

            'item_type' => (string) ($context->payload['item_type'] ?? '') === (string) $expected,

            default => false,
        };
    }

    /**
     * The operators an academy may actually use, for the API to validate
     * against — so a rule that could never fire is refused at the edge rather
     * than discovered by somebody wondering where their points went.
     *
     * @return list<string>
     */
    public static function operators(): array
    {
        return ['min_percent', 'min_points', 'passed', 'is_late', 'course_id', 'item_type'];
    }
}
