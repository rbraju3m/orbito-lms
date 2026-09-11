<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

enum RefundStatus: string
{
    /*
     * Claimed against the order, not yet confirmed. Counts against what is
     * left to refund, so two refunds racing cannot both take the last of it.
     */
    case Pending = 'pending';
    case Completed = 'completed';
    /* The provider refused. The amount is free to refund again. */
    case Failed = 'failed';

    /** Whether it holds part of the order's refundable amount. */
    public function claimsAmount(): bool
    {
        return $this !== self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Refunded',
            self::Failed => 'Failed',
        };
    }
}
