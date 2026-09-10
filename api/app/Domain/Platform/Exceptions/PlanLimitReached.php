<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Domain\Platform\Data\LimitStatus;
use App\Support\Exceptions\DomainException;

/**
 * 402 Payment Required — the academy's plan has no room for one more.
 *
 * The same reasoning as `SubscriptionLapsed`, and deliberately the same
 * status: nothing is wrong with the caller or their permissions, so 403 would
 * send them looking for a role they already have. The remedy is a bigger
 * plan, which is a payment.
 *
 * `meta` carries what the caller can DO about it (§ Phase 9): which metric,
 * the cap, what is already used, and the plan it came from. A limit error
 * that cannot say "3 of 3 courses on Starter" is a dead end.
 */
final class PlanLimitReached extends DomainException
{
    public static function for(LimitStatus $status, ?string $planName = null): self
    {
        $metric = $status->metric;

        $exception = new self(sprintf(
            'Your plan includes %s %s. Upgrading adds room for more.',
            (string) $status->limit,
            mb_strtolower($metric->label()),
        ));

        $exception->details = [[
            'code' => $metric->value,
            'message' => sprintf('%s: %d of %d used.', $metric->label(), $status->used, (int) $status->limit),
        ]];

        $exception->meta = array_filter([
            'metric' => $metric->value,
            'metric_label' => $metric->label(),
            'limit' => $status->limit,
            'used' => $status->used,
            'plan' => $planName,
        ], static fn (mixed $value): bool => $value !== null);

        return $exception;
    }

    public function errorCode(): string
    {
        return 'plan_limit_reached';
    }

    public function status(): int
    {
        return 402;
    }
}
