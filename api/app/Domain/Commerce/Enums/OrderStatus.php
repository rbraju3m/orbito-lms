<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * An order's life. Access is granted on becoming `paid`, which happens two
 * ways: `awaiting_payment → paid` by a verified webhook (ADR-05), or
 * `pending → paid` at checkout for an order the SERVER priced at zero — a
 * coupon took it to nothing, so there is no money to verify
 * (`CompleteFreeOrder`).
 */
enum OrderStatus: string
{
    /** Being built. No payment attempted. */
    case Pending = 'pending';
    /** Handed to a gateway. The learner may be on the gateway's page. */
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function isPayable(): bool
    {
        return $this === self::Pending || $this === self::AwaitingPayment;
    }

    public function grantsAccess(): bool
    {
        return $this === self::Paid;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not paid',
            self::AwaitingPayment => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }
}
