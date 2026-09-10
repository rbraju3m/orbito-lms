<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\DownloadGrant;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Support\RevenueAllocator;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
    public function __construct(private readonly RevenueAllocator $allocator) {}

    public function handle(User $user, Cart $cart): Order
    {
        $cart->loadMissing('items.product.prices');

        if ($cart->items->isEmpty()) {
            throw CheckoutRejected::emptyCart();
        }

        $currency = strtoupper($cart->currency);
        $lines = [];
        /** @var list<array<int, int>> $allocations one map per line, index-aligned */
        $allocations = [];
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

            // Computed HERE, from the prices this method already read, and
            // stored with the line. See allocationFor().
            $allocations[] = $this->allocationFor($product, $amount, $currency);
        }

        return DB::transaction(function () use ($user, $cart, $currency, $lines, $allocations, $subtotal): Order {
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

            $created = $order->items()->createMany($lines);

            /*
             * Index-aligned with $lines, because createMany returns them in
             * the order given. A bundle's money has to reach the courses it
             * contains or it counts in the platform total and in no course
             * figure at all.
             */
            foreach ($created as $index => $item) {
                foreach ($allocations[$index] ?? [] as $courseId => $amountMinor) {
                    $item->allocations()->create([
                        'course_id' => $courseId,
                        'amount_minor' => $amountMinor,
                    ]);
                }
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
     * buyer.
     *
     * Partial overlap sells: refusing a five-course bundle because of one
     * purchase last year is hostile, and the buyer is shown what they already
     * own before they pay rather than being stopped. See docs/BUNDLES.md §1.
     */
    private function assertBundleHasSomethingToDeliver(User $user, Product $product): void
    {
        $courseIds = $this->bundleCourseIds($product);

        if ($courseIds === []) {
            // An empty bundle cannot be published, so this means one was
            // emptied after somebody put it in their basket.
            throw CheckoutRejected::unavailable($product->title);
        }

        $owned = Enrollment::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->distinct()
            ->count('course_id');

        if ($owned >= count($courseIds)) {
            throw CheckoutRejected::bundleFullyOwned($product->title);
        }
    }

    /**
     * How this line's money is attributed to courses.
     *
     * A course line needs none — the line IS the attribution, and
     * `courseRevenue()` reads it directly. A bundle's price is split across
     * what it contains, weighted by each course's own list price, largest
     * remainder so the parts sum to the line EXACTLY.
     *
     * @return array<int, int> course id => minor units
     */
    private function allocationFor(Product $product, int $amountMinor, string $currency): array
    {
        if ($product->purchasable_type !== 'bundle') {
            return [];
        }

        $weights = [];

        foreach ($this->bundleCourses($product) as $course) {
            // A free course in a bundle has no product, so weight 0 — it is
            // worth none of the price. If they are ALL free the allocator
            // splits evenly rather than dropping the money.
            $weights[$course->id] = $course->product?->priceIn($currency)?->effectiveMinor() ?? 0;
        }

        return $this->allocator->allocate($amountMinor, $weights);
    }

    /** @return EloquentCollection<int, Course> */
    private function bundleCourses(Product $product): EloquentCollection
    {
        $bundle = Bundle::with(['courses.product.prices'])->find($product->purchasable_id);

        if ($bundle === null) {
            /** @var EloquentCollection<int, Course> */
            return new EloquentCollection;
        }

        return $bundle->courses;
    }

    /** @return list<int> */
    private function bundleCourseIds(Product $product): array
    {
        return $this->bundleCourses($product)->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
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
