<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Exceptions\CouponInUse;
use App\Domain\Commerce\Models\Coupon;

/**
 * Only a coupon nobody has used. One on an order — paid or not — is part of
 * that order's record: it is switched off instead. Refused here with a reason;
 * the RESTRICT foreign key on `coupon_redemptions` is the floor under it.
 */
final class DeleteCoupon
{
    public function handle(Coupon $coupon): void
    {
        $used = $coupon->redemptions()->count();

        if ($used > 0) {
            throw CouponInUse::redeemed($used);
        }

        $coupon->delete();
    }
}
