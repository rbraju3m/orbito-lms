<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Models\Subscription;
use App\Domain\Platform\Models\Tenant;
use Carbon\CarbonInterface;

/**
 * Moves an academy onto a plan, and renews it.
 *
 * This is the operator's manual lever until Phase 10 puts a real payment
 * behind it — which is why it lives on the admin surface and takes an explicit
 * period end rather than deriving one from a billing cycle that does not exist
 * yet.
 */
final class AssignPlan
{
    public function handle(
        Tenant $tenant,
        Plan $plan,
        ?CarbonInterface $periodEndsAt = null,
    ): Subscription {
        $subscription = Subscription::firstOrNew(['tenant_id' => $tenant->id]);

        $endsAt = $periodEndsAt ?? now()->addMonth();

        $subscription->fill([
            'plan_id' => $plan->id,
            'current_period_starts_at' => $subscription->current_period_starts_at ?? now(),
            'current_period_ends_at' => $endsAt,
            // Re-copied from the plan on every assignment: moving an academy
            // to a plan with a longer grace period should give it that period
            // from now on, without retroactively changing why it lapsed before.
            'grace_days' => $plan->grace_days,
            'canceled_at' => null,
        ]);

        /*
         * Renewal REVIVES a lapsed academy — that is the entire point of the
         * operator being able to do this. Left as-is, an expired subscription
         * would keep 402ing writes even after somebody paid, and the status
         * only degrades in the nightly sweep, so nothing else would fix it.
         */
        if ($endsAt->isFuture()) {
            $subscription->status = SubscriptionStatus::Active;
        }

        $subscription->save();

        return $subscription->refresh()->load('plan');
    }
}
