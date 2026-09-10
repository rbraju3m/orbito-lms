<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\Cart;

final class RemoveCoupon
{
    public function handle(Cart $cart): Cart
    {
        $cart->forceFill(['coupon_id' => null])->save();

        return $cart->refresh();
    }
}
