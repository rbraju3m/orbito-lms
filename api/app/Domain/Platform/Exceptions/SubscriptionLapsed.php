<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Domain\Platform\Models\Subscription;
use App\Support\Exceptions\DomainException;

/**
 * 402 Payment Required — the one status that says exactly this.
 *
 * Not 403: nothing is wrong with the caller or their permissions, and the
 * remedy is a payment rather than a different account.
 */
final class SubscriptionLapsed extends DomainException
{
    public static function from(?Subscription $subscription): self
    {
        $exception = new self(
            'This academy\'s subscription has lapsed. You can still read and export everything; '
            .'renewing restores the ability to make changes.',
        );

        $exception->details = [[
            'code' => $subscription?->status->value ?? 'no_subscription',
            'message' => $subscription?->status->label() ?? 'No subscription',
        ]];

        $exception->meta = array_filter([
            'status' => $subscription?->status->value,
            'cover_ended_at' => $subscription?->coverEndsAt()?->toIso8601String(),
        ], static fn (mixed $v): bool => $v !== null);

        return $exception;
    }

    public function errorCode(): string
    {
        return 'subscription_lapsed';
    }

    public function status(): int
    {
        return 402;
    }
}
