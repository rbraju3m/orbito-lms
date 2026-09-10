<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Policies;

use App\Domain\Commerce\Models\Coupon;
use App\Domain\Identity\Models\User;

/**
 * `coupon.manage` — held by Admin and Super Admin (docs/ROLES_PERMISSIONS.md).
 * A coupon is a decision about the academy's prices, so it is academy-wide;
 * an instructor cannot discount their own course.
 */
final class CouponPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('coupon.manage');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('coupon.manage');
    }

    public function view(User $actor, Coupon $coupon): bool
    {
        return $actor->hasPermission('coupon.manage');
    }

    public function update(User $actor, Coupon $coupon): bool
    {
        return $actor->hasPermission('coupon.manage');
    }

    public function delete(User $actor, Coupon $coupon): bool
    {
        return $actor->hasPermission('coupon.manage');
    }
}
