<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use App\Domain\Commerce\Support\Money;
use App\Support\Exceptions\DomainException;

/** A refund that cannot be made, and — in `meta` — what could be. */
final class RefundRejected extends DomainException
{
    public static function notPaid(): self
    {
        return self::make('not_paid', 'Only a paid order can be refunded.');
    }

    public static function nothingLeft(): self
    {
        return self::make('nothing_left', 'This order has nothing left to refund.');
    }

    public static function tooMuch(int $refundable, string $currency): self
    {
        return self::make(
            'too_much',
            'At most '.Money::format($refundable, $currency).' can still be refunded on this order.',
            ['refundable_minor' => $refundable],
        );
    }

    /** A free order, or one settled by hand: there is no payment to send it back through. */
    public static function noPayment(): self
    {
        return self::make(
            'no_payment',
            'There is no captured payment to refund through. Record a refund made elsewhere instead.',
        );
    }

    /** @param  array<string, mixed>  $meta */
    private static function make(string $reason, string $message, array $meta = []): self
    {
        $exception = new self($message);
        $exception->meta = ['reason' => $reason, ...$meta];

        return $exception;
    }

    public function errorCode(): string
    {
        return 'refund_rejected';
    }

    public function status(): int
    {
        return 422;
    }
}
