<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

enum DiscountType: string
{
    /* `percent_off`, 1–100, rounded down. */
    case Percent = 'percent';
    /* `amount_off_minor` in the coupon's currency, never more than it applies to. */
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percentage off',
            self::Fixed => 'Fixed amount off',
        };
    }
}
