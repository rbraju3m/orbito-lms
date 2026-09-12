<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Support\WebinarPurchase;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Models\Webinar;
use Illuminate\Support\Facades\DB;

/**
 * Puts one product in the learner's basket, creating the basket if needed.
 *
 * The refusals happen HERE as well as at checkout, and that repetition is
 * deliberate. `PlaceOrder` has to re-check everything anyway — a product can
 * be retired between adding and paying — but finding out at the checkout
 * button that a course was never purchasable is a worse experience than being
 * told at the moment of adding it. Neither check may be removed in favour of
 * the other: this one is for the human, that one is for correctness.
 */
final class AddToCart
{
    public function handle(User $user, Product $product): Cart
    {
        $cart = $this->cartFor($user);

        if (! $product->status->isSellable()) {
            throw CheckoutRejected::unavailable($product->title);
        }

        // No price in the basket's currency means this product cannot join
        // THIS basket, even though it is perfectly sellable in another.
        if ($product->priceIn($cart->currency) === null) {
            throw CheckoutRejected::noPrice($product->title, $cart->currency);
        }

        $this->assertNotAlreadyOwned($user, $product);

        /*
         * firstOrCreate, not create: adding the same course twice is the
         * learner clicking twice, not an error worth a 409. The unique key on
         * (cart_id, product_id) is what makes that true rather than hopeful.
         */
        $cart->items()->firstOrCreate(['product_id' => $product->id]);

        return $cart->load('items.product.prices');
    }

    /**
     * One open basket per learner.
     *
     * The currency is fixed when the basket is created and never changes
     * afterwards: a basket that re-prices itself as items go in is a basket
     * whose total depends on the order things were added in.
     */
    private function cartFor(User $user): Cart
    {
        return DB::transaction(fn (): Cart => Cart::firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => strtoupper((string) config('orbito.currency.base'))],
        ));
    }

    /** Same rule PlaceOrder enforces, and for the same reason. */
    private function assertNotAlreadyOwned(User $user, Product $product): void
    {
        if ($product->purchasable_type === 'webinar') {
            $webinar = Webinar::find($product->purchasable_id);

            /*
             * A place, not a thing: already held, already over and already
             * full are all reasons this basket could never be delivered, and
             * `WebinarPurchase` is the one definition of them — asked again by
             * `PlaceOrder`, because the room can fill while somebody reads the
             * page.
             */
            if ($webinar !== null) {
                WebinarPurchase::assertBuyable($user, $webinar);
            }

            return;
        }

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
}
