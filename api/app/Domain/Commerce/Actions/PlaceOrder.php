<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\DownloadGrant;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\CouponRedemption;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Support\AllocationTarget;
use App\Domain\Commerce\Support\CouponRules;
use App\Domain\Commerce\Support\RevenueAllocator;
use App\Domain\Commerce\Support\WebinarPurchase;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Models\Webinar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a cart into an order, at prices the SERVER reads now.
 *
 * This is where ADR-05 is won or lost. The request body contributes nothing to
 * the total: not a price, not a quantity, not a discount. Every figure below
 * comes from `product_prices` at this moment, which is why `cart_items` stores
 * no price to be tempted by — and the coupon on the cart is only a POINTER,
 * asked again here whether it still applies and for how much.
 */
final class PlaceOrder
{
    public function __construct(private readonly RevenueAllocator $allocator) {}

    public function handle(User $user, Cart $cart): Order
    {
        $cart->loadMissing('items.product.prices');

        if ($cart->items->isEmpty()) {
            throw CheckoutRejected::emptyCart();
        }

        $currency = strtoupper($cart->currency);
        $lines = [];
        /** @var list<Product> $products index-aligned with $lines */
        $products = [];
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
                'discount_minor' => 0,
                'total_minor' => $amount,
            ];
            $products[] = $product;
        }

        return DB::transaction(function () use ($user, $cart, $currency, $lines, $products, $subtotal): Order {
            /*
             * The coupon is read, and its limits counted, BEHIND A LOCK on its
             * own row, inside the transaction that writes the redemption —
             * two learners racing for the last use must not both get it (§
             * Patterns established in Phase 9: count and insert in ONE
             * transaction). A coupon deleted since it was applied has nulled
             * the pointer, so there is simply no coupon.
             */
            $coupon = $cart->coupon_id !== null
                ? Coupon::query()->lockForUpdate()->find($cart->coupon_id)
                : null;

            if ($coupon !== null) {
                $rules = CouponRules::for($coupon, $user->id, $currency, array_map(
                    static fn (array $line): array => [
                        'product_id' => $line['product_id'],
                        'amount_minor' => $line['unit_amount_minor'],
                    ],
                    $lines,
                ));

                // Refuse the checkout rather than charge a price the learner
                // was not shown: the basket said this coupon applied.
                $rules->assertApplies();

                foreach ($rules->discounts() as $index => $discount) {
                    $lines[$index]['discount_minor'] = $discount;
                    $lines[$index]['total_minor'] = $lines[$index]['unit_amount_minor'] - $discount;
                }
            }

            $discount = array_sum(array_column($lines, 'discount_minor'));

            $order = Order::create([
                'number' => $this->nextNumber(),
                'user_id' => $user->id,
                'status' => OrderStatus::Pending,
                'currency' => $currency,
                'coupon_id' => $coupon?->id,
                'coupon_code' => $coupon?->code,
                'subtotal_minor' => $subtotal,
                'discount_minor' => $discount,
                // Equal to the sum of the line totals, by construction — that
                // equality is what every revenue figure relies on.
                'total_minor' => $subtotal - $discount,
                'placed_at' => now(),
            ]);

            $created = $order->items()->createMany($lines);

            /*
             * Index-aligned with $lines, because createMany returns them in
             * the order given. A bundle's money has to reach the courses and
             * downloads it contains or it counts in the platform total and in
             * no course or download figure at all — and it is the NET line
             * total that is split, so a discounted bundle's contents earn
             * their share of what was actually charged.
             */
            foreach ($created as $index => $item) {
                foreach ($this->allocationFor($products[$index], $item->total_minor, $currency) as $target => $amountMinor) {
                    $item->allocations()->create([
                        ...AllocationTarget::columns($target),
                        'amount_minor' => $amountMinor,
                    ]);
                }
            }

            if ($coupon !== null) {
                CouponRedemption::create([
                    'coupon_id' => $coupon->id,
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'discount_minor' => $discount,
                    'currency' => $currency,
                ]);
            }

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
        if ($product->purchasable_type === 'course') {
            $owned = Enrollment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $product->purchasable_id)
                ->exists();

            if ($owned) {
                throw CheckoutRejected::alreadyOwned($product->title);
            }

            return;
        }

        if ($product->purchasable_type === 'bundle') {
            $this->assertBundleHasSomethingToDeliver($user, $product);

            return;
        }

        /*
         * A place at an event, asked of the same rule the basket asked — and
         * asked again HERE because the room can fill, or the event pass,
         * between adding and paying. This is the last time it is asked: once
         * the payment lands, `GrantOrderAccess` registers whatever the room
         * looks like.
         */
        if ($product->purchasable_type === 'webinar') {
            $webinar = Webinar::find($product->purchasable_id);

            if ($webinar !== null) {
                WebinarPurchase::assertBuyable($user, $webinar);
            }

            return;
        }

        // A download owned once is owned. A REVOKED grant does not count —
        // somebody refunded may buy it again.
        if ($product->purchasable_type === 'download') {
            $owned = DownloadGrant::query()
                ->where('download_id', $product->purchasable_id)
                ->where('user_id', $user->id)
                ->active()
                ->exists();

            if ($owned) {
                throw CheckoutRejected::alreadyOwned($product->title);
            }
        }
    }

    /**
     * A bundle is refused only when there is NOTHING left in it for this
     * buyer — no course they are not enrolled in, and no download they do not
     * hold.
     *
     * Partial overlap sells: refusing a five-course bundle because of one
     * purchase last year is hostile, and the buyer is shown what they already
     * own before they pay rather than being stopped. See docs/BUNDLES.md §1.
     */
    private function assertBundleHasSomethingToDeliver(User $user, Product $product): void
    {
        $bundle = $this->bundle($product);
        $courseIds = $bundle?->courses->modelKeys() ?? [];
        $downloadIds = $bundle?->downloads->modelKeys() ?? [];

        if ($courseIds === [] && $downloadIds === []) {
            // An empty bundle cannot be published, so this means one was
            // emptied after somebody put it in their basket.
            throw CheckoutRejected::unavailable($product->title);
        }

        $owned = Enrollment::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->distinct()
            ->count('course_id');

        // A REVOKED grant does not count, as for a download bought alone.
        $owned += DownloadGrant::query()
            ->where('user_id', $user->id)
            ->whereIn('download_id', $downloadIds)
            ->active()
            ->count();

        if ($owned >= count($courseIds) + count($downloadIds)) {
            throw CheckoutRejected::bundleFullyOwned($product->title);
        }
    }

    /**
     * How this line's money is attributed.
     *
     * A course or download line needs none — the line IS the attribution. A
     * bundle's price is split across what it contains, weighted by each
     * part's own list price, largest remainder so the parts sum to the line
     * EXACTLY. Keyed by `AllocationTarget`, because a course and a download
     * can share an id.
     *
     * @return array<string, int> target key => minor units
     */
    private function allocationFor(Product $product, int $amountMinor, string $currency): array
    {
        if ($product->purchasable_type !== 'bundle') {
            return [];
        }

        $bundle = $this->bundle($product);

        if ($bundle === null) {
            return [];
        }

        $weights = [];

        // Anything free has no product, so weight 0 — it is worth none of the
        // price. If EVERYTHING is free the allocator splits evenly rather
        // than dropping the money.
        foreach ($bundle->courses as $course) {
            $weights[AllocationTarget::course($course->id)] = $course->product?->priceIn($currency)?->effectiveMinor() ?? 0;
        }

        foreach ($bundle->downloads as $download) {
            $weights[AllocationTarget::download($download->id)] = $download->product?->priceIn($currency)?->effectiveMinor() ?? 0;
        }

        return $this->allocator->allocateTargets($amountMinor, $weights);
    }

    private function bundle(Product $product): ?Bundle
    {
        return Bundle::with(['courses.product.prices', 'downloads.product.prices'])->find($product->purchasable_id);
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
