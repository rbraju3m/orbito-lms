<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Commerce\Models\Product;

/**
 * What a purchasable costs, in the shape the catalogue renders.
 *
 * Shared by courses and bundles because the answer has the same shape for
 * both and two copies would drift. It reads Commerce for DISPLAY only — the
 * figure that CHARGES is the one `PlaceOrder` re-reads at checkout (ADR-05),
 * and this is explicitly not it. The UI must treat it as a label, never an
 * input.
 *
 * Null means "not for sale right now", which is a different fact from free.
 */
final class PriceView
{
    /** @return array<string, mixed>|null */
    public static function for(?Product $product, string $currency): ?array
    {
        if ($product === null || ! $product->status->isSellable()) {
            return null;
        }

        $price = $product->priceIn($currency);

        if ($price === null) {
            return null;
        }

        return [
            // The id the basket speaks. Without it the buy button would have
            // to look the product up, which is a second request for something
            // the page already knows.
            'product_id' => $product->uuid,
            'currency' => $price->currency,
            'amount_minor' => $price->effectiveMinor(),
            // Present only during a sale, so "was £99" is a fact the UI can
            // render rather than something it has to infer.
            'list_amount_minor' => $price->isOnSale() ? $price->amount_minor : null,
            'is_on_sale' => $price->isOnSale(),
        ];
    }
}
