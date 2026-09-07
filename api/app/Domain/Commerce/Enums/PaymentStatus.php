<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

enum PaymentStatus: string
{
    /** Row created, gateway not yet answered. */
    case Initiated = 'initiated';
    /** Gateway acknowledged; awaiting the webhook that settles it. */
    case Pending = 'pending';
    case Captured = 'captured';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isSettled(): bool
    {
        return $this === self::Captured;
    }

    /** Whether a webhook may still move it. A captured payment may not. */
    public function isOpen(): bool
    {
        return $this === self::Initiated || $this === self::Pending;
    }
}
