<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Support;

/**
 * "BDT 49.00", for a sentence a person reads — a refusal message, never a
 * stored figure. Two decimals is right for every currency the platform sells
 * in today; a zero-decimal currency (JPY) would need the minor-unit table
 * DATABASE.md sketches as `currencies`, and this is where it would be read.
 */
final class Money
{
    public static function format(int $minor, string $currency): string
    {
        return strtoupper($currency).' '.number_format($minor / 100, 2);
    }
}
