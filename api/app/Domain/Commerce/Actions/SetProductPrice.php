<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Exceptions\PricingRejected;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductPrice;
use Carbon\CarbonInterface;

/**
 * What one purchasable costs, in one currency.
 *
 * The ONLY write path for `product_prices`, so every rule about money is in
 * one place: the currency must be one the platform supports, a sale must be
 * cheaper than the list price, and a sale window must not end before it
 * starts. Getting any of those wrong is a refund conversation.
 *
 * One row per (product, currency). Setting a price twice replaces it rather
 * than accumulating, and clearing the sale is passing null — not deleting the
 * row, which would remove the list price with it.
 */
final class SetProductPrice
{
    public function handle(
        Product $product,
        string $currency,
        int $amountMinor,
        ?int $saleAmountMinor = null,
        ?CarbonInterface $saleStartsAt = null,
        ?CarbonInterface $saleEndsAt = null,
    ): ProductPrice {
        $currency = strtoupper($currency);

        /** @var list<string> $supported */
        $supported = config('orbito.currency.supported', []);

        if (! in_array($currency, $supported, true)) {
            throw PricingRejected::unsupportedCurrency($currency);
        }

        if ($amountMinor < 1) {
            // Zero is not a price. A course that costs nothing is `free`,
            // which is a different path with no product and no checkout.
            throw PricingRejected::notPositive();
        }

        if ($saleAmountMinor !== null && $saleAmountMinor >= $amountMinor) {
            throw PricingRejected::saleNotCheaper();
        }

        if ($saleStartsAt !== null && $saleEndsAt !== null && $saleEndsAt->lessThanOrEqualTo($saleStartsAt)) {
            throw PricingRejected::saleWindowInverted();
        }

        return ProductPrice::query()->updateOrCreate(
            ['product_id' => $product->id, 'currency' => $currency],
            [
                'amount_minor' => $amountMinor,
                'sale_amount_minor' => $saleAmountMinor,
                'sale_starts_at' => $saleStartsAt,
                'sale_ends_at' => $saleEndsAt,
            ],
        );
    }
}
