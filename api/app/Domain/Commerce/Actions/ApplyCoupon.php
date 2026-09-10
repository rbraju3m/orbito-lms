<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Exceptions\CouponRejected;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Support\CouponRules;
use App\Domain\Identity\Models\User;

/**
 * Puts a coupon on a basket — only if it applies to it right now, so the
 * learner hears "not valid" at the moment they type it, not at checkout.
 *
 * What is stored is a pointer. The discount is recomputed on every read of
 * the basket and again, under a lock, when the order is placed.
 */
final class ApplyCoupon
{
    public function handle(User $user, Cart $cart, string $code): Cart
    {
        $coupon = Coupon::query()->where('code', Coupon::normalise($code))->first();

        if ($coupon === null) {
            throw CouponRejected::notFound();
        }

        CouponRules::for($coupon, $user->id, $cart->currency, $cart->pricedLines())->assertApplies();

        $cart->forceFill(['coupon_id' => $coupon->id])->save();

        return $cart->refresh();
    }
}
