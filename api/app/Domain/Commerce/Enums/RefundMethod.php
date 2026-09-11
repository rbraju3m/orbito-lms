<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

enum RefundMethod: string
{
    /* Sent back through the gateway that took the payment. */
    case Gateway = 'gateway';
    /*
     * Already given back somewhere else — the provider's own dashboard, a bank
     * transfer, cash — and recorded here so the books and the access match.
     * Nothing is called.
     */
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Gateway => 'Through the payment provider',
            self::External => 'Recorded — refunded elsewhere',
        };
    }
}
