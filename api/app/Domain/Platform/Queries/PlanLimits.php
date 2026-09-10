<?php

declare(strict_types=1);

namespace App\Domain\Platform\Queries;

use App\Domain\Platform\Data\LimitStatus;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Exceptions\PlanLimitReached;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Support\UsageCounters;

/**
 * The ONE answer to "does this academy's plan have room for one more?".
 *
 * The `plans` migration has named this class since Phase 1; this is it. Every
 * context asks it the same way `CourseAccess` (ADR-03) is asked "may they
 * consume this?" — a shared service crossing contexts on purpose, because a
 * limit has to be answered synchronously and an event cannot say no. Adding a
 * capped dimension means a `UsageMetric` case with a `planKey()`, not a second
 * check somewhere else.
 *
 * Which academy? The one the request is already inside. `UsageCounters`
 * scopes itself the same way, and taking a tenant argument here would be one
 * more thing a caller can pass wrong — there is exactly one academy a request
 * may be capped against.
 *
 * NOTHING here is memoised, and `SubscriptionState` is deliberately not a
 * shared binding either. Both halves of the answer move underneath this class:
 * the counters as the request writes, and the plan the moment an operator
 * reassigns one. Sharing the lookup across a container that outlives a request
 * — which Laravel's is under Octane, and inside a test that makes two calls —
 * hands the second read the first read's plan. `CourseAccess` was burned by
 * exactly this (§ Phase 9); a limit is close enough to an authorization
 * decision to follow the same rule. Two subscription lookups on a write path
 * is the price, and it is small.
 */
final class PlanLimits
{
    public function __construct(
        private readonly SubscriptionState $subscriptions,
        private readonly UsageCounters $counters,
    ) {}

    /**
     * Throw unless there is room for `$by` more.
     *
     * NOT atomic with the insert that follows, and it cannot be: the counters
     * are central and the rows they cap are in the academy's own schema, so no
     * single transaction spans both. Two simultaneous creates can therefore
     * both find the last slot — the same race the course seat limit closes
     * with `lockForUpdate()`, which is unavailable here. The consequence is
     * bounded and acceptable: an academy can end up one over its plan, the
     * nightly `usage:reconcile` reports it, and nobody has lost a seat they
     * paid for. Overselling a COURSE would cost a learner their place; being
     * one course over a billing cap costs nothing but a number.
     */
    public function assert(UsageMetric $metric, int $by = 1): void
    {
        $status = $this->status($metric);

        if (! $status->enforced || $status->permits($by)) {
            return;
        }

        throw PlanLimitReached::for($status, $this->plan()?->name);
    }

    public function permits(UsageMetric $metric, int $by = 1): bool
    {
        $status = $this->status($metric);

        return ! $status->enforced || $status->permits($by);
    }

    public function status(UsageMetric $metric): LimitStatus
    {
        return new LimitStatus(
            metric: $metric,
            used: $this->counters->get($metric),
            limit: $this->allowance($metric),
            enforced: $metric->isEnforced(),
        );
    }

    /**
     * Every sold dimension, for the usage panel.
     *
     * @return list<LimitStatus>
     */
    public function all(): array
    {
        return array_map(
            fn (UsageMetric $metric): LimitStatus => $this->status($metric),
            UsageMetric::billable(),
        );
    }

    /**
     * The cap for one metric, or null for uncapped.
     *
     * An academy with no subscription row is uncapped rather than capped at
     * zero. `EnsureActiveSubscription` already fails a missing row CLOSED for
     * every write, so it can never reach here in production — and if it
     * somehow does, "you may create nothing" is the wrong way to be wrong.
     */
    public function allowance(UsageMetric $metric): ?int
    {
        $key = $metric->planKey();

        if ($key === null) {
            return null;
        }

        return $this->plan()?->limit($key);
    }

    public function plan(): ?Plan
    {
        $tenantId = tenancy()->tenant?->getTenantKey();

        if ($tenantId === null) {
            return null;
        }

        return $this->subscriptions->for((string) $tenantId)?->plan;
    }
}
