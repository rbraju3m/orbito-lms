<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * An order's life. The only transition that grants access is
 * `awaiting_payment → paid`, and only a verified webhook makes it (ADR-05).
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
