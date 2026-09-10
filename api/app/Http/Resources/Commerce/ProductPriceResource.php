<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Models\ProductPrice;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A price as its AUTHOR sees it: what is stored, whether or not the product
 * is currently sellable.
 *
 * The catalogue's answer is `PriceView`, which returns null for anything not
 * on sale — correct there, and useless here, because a draft course's product
 * is always inactive and the author would be handed null for the price they
 * just set (ADR-06: two audiences, two resources).
 *
 * @mixin ProductPrice
 */
final class ProductPriceResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'currency' => $this->currency,
            'amount_minor' => $this->amount_minor,

            'sale_amount_minor' => $this->sale_amount_minor,
            'sale_starts_at' => $this->sale_starts_at?->toIso8601String(),
            'sale_ends_at' => $this->sale_ends_at?->toIso8601String(),

            // Evaluated live from the clock, never stored: a sale that ends at
            // midnight ends at midnight, not when a job next runs.
            'is_on_sale' => $this->isOnSale(),
            'effective_amount_minor' => $this->effectiveMinor(),
        ];
    }
}
