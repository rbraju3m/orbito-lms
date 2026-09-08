<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\CartItem;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * The basket, with a total the SERVER computed.
 *
 * The total is an ESTIMATE and says so through `is_checkoutable`: it is what
 * checkout would charge if it ran now. The figure that matters is the one
 * `PlaceOrder` writes onto the order, re-read at that moment from
 * `product_prices`. Nothing here is ever an input to that.
 *
 * Lines are shaped by a private method rather than a `CartItemResource`. A
 * line has no meaning apart from its basket — it needs the basket's currency
 * to have a price at all, and there is no endpoint that addresses one on its
 * own. A separate resource would have had to walk back up to its parent for
 * the currency, which under strict mode is a lazy load waiting to happen.
 *
 * @mixin Cart
 */
final class CartResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->loadMissing('items.product.prices');

        $lines = $this->items->map(fn (CartItem $item): array => $this->line($item));

        return [
            'id' => $this->uuid,
            'currency' => $this->currency,
            'item_count' => $lines->count(),
            'estimated_total_minor' => (int) $lines->sum('amount_minor'),
            // The button state, decided here so a component cannot invent its
            // own rule and disagree with what checkout will actually do.
            'is_checkoutable' => $lines->isNotEmpty() && $lines->every('is_available'),
            'items' => $lines->values()->all(),
        ];
    }

    /**
     * One line, priced LIVE.
     *
     * `cart_items` stores no price (ADR-05), so every figure is read now. If a
     * sale ends between viewing the basket and checking out, the two disagree
     * — which is honest, because the order is the thing that charges.
     *
     * @return array<string, mixed>
     */
    private function line(CartItem $item): array
    {
        $product = $item->product;
        $price = $product->priceIn($this->currency);

        return [
            'id' => (string) $item->id,
            'product_id' => $product->uuid,
            'title' => $product->title,
            'purchasable_type' => $product->purchasable_type,
            'purchasable_id' => $product->purchasable_id,

            // Null when the product stopped being sold in this currency while
            // it sat in the basket: the line is unavailable, not free.
            'amount_minor' => $price?->effectiveMinor(),
            'list_amount_minor' => $price?->amount_minor,
            'is_on_sale' => $price?->isOnSale() ?? false,
            'is_available' => $price !== null && $product->status->isSellable(),
        ];
    }
}
