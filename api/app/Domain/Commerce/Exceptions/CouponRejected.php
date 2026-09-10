<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use App\Domain\Commerce\Enums\CouponRefusal;
use App\Support\Exceptions\DomainException;

/**
 * A coupon that cannot be used, and why — `meta.reason` is the machine code,
 * plus whatever the learner can act on (a start date, a minimum spend).
 */
final class CouponRejected extends DomainException
{
    /** @param  array<string, mixed>  $meta */
    public static function because(CouponRefusal $refusal, string $message, array $meta = []): self
    {
        $exception = new self($message);
        $exception->meta = ['reason' => $refusal->value, ...$meta];

        return $exception;
    }

    public static function notFound(): self
    {
        return self::because(CouponRefusal::NotFound, 'That code does not exist.');
    }

    public function errorCode(): string
    {
        return 'coupon_rejected';
    }

    public function status(): int
    {
        return 422;
    }
}
