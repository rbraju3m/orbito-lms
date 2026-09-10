<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

use App\Domain\Commerce\Models\Coupon;
use Illuminate\Support\Facades\Date;

/**
 * What the admin list says about a coupon at a glance — derived from its
 * switch, its dates and how often it has been PAID for, never stored, so it
 * is right the moment a window opens or closes (§ Patterns established in
 * Phase 15: derive a status from the clock).
 *
 * A summary, not the rule. Whether a coupon applies to a basket is
 * `CouponRules`' question, which also counts unpaid orders still holding a use.
 */
enum CouponState: string
{
    case Active = 'active';
    case Scheduled = 'scheduled';
    case Expired = 'expired';
    case UsedUp = 'used_up';
    case Off = 'off';

    public static function of(Coupon $coupon, int $paidRedemptions): self
    {
        $now = Date::now();

        return match (true) {
            ! $coupon->is_active => self::Off,
            $coupon->ends_at !== null && ! $coupon->ends_at->isAfter($now) => self::Expired,
            $coupon->max_redemptions !== null && $paidRedemptions >= $coupon->max_redemptions => self::UsedUp,
            $coupon->starts_at !== null && $coupon->starts_at->isAfter($now) => self::Scheduled,
            default => self::Active,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Scheduled => 'Scheduled',
            self::Expired => 'Expired',
            self::UsedUp => 'Used up',
            self::Off => 'Switched off',
        };
    }
}
