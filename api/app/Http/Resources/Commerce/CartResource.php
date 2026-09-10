<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\CartItem;
use App\Domain\Commerce\Support\CouponRules;
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
        $this->loadMissing(['items.product.prices', 'coupon']);

        // The SAME rules checkout enforces, asked of the same live prices, so
        // the preview and the order cannot disagree about a discount.
        $coupon = $this->coupon;
        $rules = $coupon !== null
            ? CouponRules::for($coupon, (int) $request->user()?->getAuthIdentifier(), $this->currency, $this->resource->pricedLines())
            : null;
        $discounts = $rules?->discounts() ?? [];

        $lines = $this->items->map(fn (CartItem $item): array => $this->line($item, $discounts[$item->id] ?? 0));
        $subtotal = (int) $lines->sum('amount_minor');
        $discount = $rules?->total() ?? 0;

        return [
            'id' => $this->uuid,
            'currency' => $this->currency,
            'item_count' => $lines->count(),
            'estimated_subtotal_minor' => $subtotal,
            'estimated_discount_minor' => $discount,
            'estimated_total_minor' => $subtotal - $discount,
            /*
             * The coupon on the basket, and whether it still applies — it can
             * stop (it expired, a line was removed, the last use went) while
             * sitting here. A reason the page can show, rather than a checkout
             * that refuses.
             */
            'coupon' => $coupon === null || $rules === null ? null : [
                'code' => $coupon->code,
                'description' => $coupon->description,
                'applies' => $rules->applies(),
                'reason' => $rules->refusal()?->value,
                'message' => $rules->message(),
            ],
            // The button state, decided here so a component cannot invent its
            // own rule and disagree with what checkout will actually do —
            // which includes refusing a coupon that no longer applies.
            'is_checkoutable' => $lines->isNotEmpty()
                && $lines->every('is_available')
                && ($rules === null || $rules->applies()),
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
    private function line(CartItem $item, int $discount): array
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
            // This line's share of the coupon, as checkout would split it.
            'discount_minor' => $discount,
            'is_available' => $price !== null && $product->status->isSellable(),
        ];
    }
}
