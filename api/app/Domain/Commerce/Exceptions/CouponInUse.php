<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use App\Support\Exceptions\DomainException;

/** A used coupon is part of somebody's receipt: it is switched off, not deleted. */
final class CouponInUse extends DomainException
{
    public static function redeemed(int $times): self
    {
        $exception = new self(sprintf(
            'This coupon is on %d %s, so it cannot be deleted. Switch it off instead.',
            $times,
            $times === 1 ? 'order' : 'orders',
        ));
        $exception->meta = ['redemptions' => $times];

        return $exception;
    }

    public function errorCode(): string
    {
        return 'coupon_in_use';
    }

    public function status(): int
    {
        return 409;
    }
}
