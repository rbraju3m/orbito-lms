<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Events;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Refund;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money went back to a learner — a refund COMPLETED, not merely asked for.
 *
 * Named in EVENTS.md §4 since Phase 10 and built with its first consumer, the
 * `refund.issued` webhook. Revenue reporting does not listen: money comes from
 * the ledger (`refunds`), never from an event.
 */
final class RefundIssued
{
    use Dispatchable;

    public function __construct(
        public readonly Refund $refund,
        public readonly Order $order,
        /** Whether this refund took the order to fully refunded. */
        public readonly bool $fullyRefunded,
    ) {}
}
