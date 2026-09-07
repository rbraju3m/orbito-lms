<?php

declare(strict_types=1);

namespace App\Domain\Platform\Queries;

use App\Domain\Platform\Models\Subscription;

/**
 * The one place a subscription is looked up for enforcement.
 *
 * Memoised per instance, because the middleware asks once per request and
 * anything else that asks in the same request wants the same answer. The
 * status is read STORED — never recomputed from dates — so two callers in one
 * request cannot disagree about whether an academy has lapsed.
 */
final class SubscriptionState
{
    /** @var array<string, Subscription|null> */
    private array $memo = [];

    public function for(string $tenantId): ?Subscription
    {
        return $this->memo[$tenantId] ??= Subscription::query()
            ->with('plan')
            ->where('tenant_id', $tenantId)
            ->first();
    }
}
