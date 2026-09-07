<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a cart into an order, at prices the SERVER reads now.
 *
 * This is where ADR-05 is won or lost. The request body contributes nothing to
 * the total: not a price, not a quantity, not a discount. Every figure below
 * comes from `product_prices` at this moment, which is why `cart_items` stores
 * no price to be tempted by.
 */
final class PlaceOrder
{
    public function handle(User $user, Cart $cart): Order
    {
        $cart->loadMissing('items.product.prices');

        if ($cart->items->isEmpty()) {
            throw CheckoutRejected::emptyCart();
        }

        $currency = strtoupper($cart->currency);
        $lines = [];
        $subtotal = 0;

        foreach ($cart->items as $item) {
            $product = $item->product;

            // The FK cascades, so a null product is belt-and-braces.
            if ($product === null) {
                throw CheckoutRejected::unavailable('An item in your basket');
            }

            if (! $product->status->isSellable()) {
                throw CheckoutRejected::unavailable($product->title);
            }

            $price = $product->priceIn($currency);

            // No price in this currency is a refusal, not a fallback. Charging
            // in the wrong currency, or for nothing, are both worse than
            // saying the sale cannot happen.
            if ($price === null) {
                throw CheckoutRejected::noPrice($product->title, $currency);
            }

            $this->assertNotAlreadyOwned($user, $product);

            $amount = $price->effectiveMinor();
            $subtotal += $amount;

            $lines[] = [
                'product_id' => $product->id,
                'purchasable_type' => $product->purchasable_type,
                'purchasable_id' => $product->purchasable_id,
                // Snapshots: editing or deleting the product later must not
                // rewrite what somebody was charged.
                'title_snapshot' => $product->title,
                'unit_amount_minor' => $amount,
                'total_minor' => $amount,
            ];
        }

        return DB::transaction(function () use ($user, $cart, $currency, $lines, $subtotal): Order {
            $order = Order::create([
                'number' => $this->nextNumber(),
                'user_id' => $user->id,
                'status' => OrderStatus::Pending,
                'currency' => $currency,
                'subtotal_minor' => $subtotal,
                'discount_minor' => 0,
                'total_minor' => $subtotal,
                'placed_at' => now(),
            ]);

            $order->items()->createMany($lines);

            // The cart is consumed. Leaving it would let a second checkout
            // create a second order for a course they are about to own.
            $cart->items()->delete();
            $cart->delete();

            return $order->load('items');
        });
    }

    /**
     * Selling somebody a course they can already open is a refund request, not
     * a sale — and the access grant afterwards would be a no-op, so nothing
     * downstream would notice.
     */
    private function assertNotAlreadyOwned(User $user, Product $product): void
    {
        if ($product->purchasable_type !== 'course') {
            return;
        }

        $owned = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $product->purchasable_id)
            ->exists();

        if ($owned) {
            throw CheckoutRejected::alreadyOwned($product->title);
        }
    }

    /**
     * Human-facing and unique per academy.
     *
     * Random rather than sequential: a strictly incrementing number tells
     * every customer how many orders the academy has taken, and getting a gap-
     * free sequence right under concurrency needs a lock this phase does not
     * otherwise require.
     */
    private function nextNumber(): string
    {
        return now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }
}
