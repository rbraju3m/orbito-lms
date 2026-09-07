<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * A pointer, not a price. Amounts live in commerce.product_prices (Phase 10)
 * as integer minor units plus a currency (ADR-04).
 */
enum PricingModel: string
{
    case Free = 'free';
    case OneTime = 'one_time';
    case Subscription = 'subscription';
    case Mixed = 'mixed';

    public function requiresPrice(): bool
    {
        return $this !== self::Free;
    }
}
