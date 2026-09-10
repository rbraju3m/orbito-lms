<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * Why a coupon does not apply, as a stable machine code (`error.meta.reason`,
 * and the cart's `coupon.reason`). The sentence a person reads is built by
 * `CouponRules`, which knows the dates and amounts to put in it.
 */
enum CouponRefusal: string
{
    case NotFound = 'not_found';
    case Inactive = 'inactive';
    case NotStarted = 'not_started';
    case Expired = 'expired';
    case WrongCurrency = 'wrong_currency';
    case NothingEligible = 'nothing_eligible';
    case BelowMinimum = 'below_minimum';
    case Exhausted = 'exhausted';
    case AlreadyUsed = 'already_used';
}
