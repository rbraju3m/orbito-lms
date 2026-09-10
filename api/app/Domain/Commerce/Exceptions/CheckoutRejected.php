<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use App\Support\Exceptions\DomainException;

final class CheckoutRejected extends DomainException
{
    public static function emptyCart(): self
    {
        return new self('Your basket is empty.');
    }

    public static function unavailable(string $title): self
    {
        return new self("“{$title}” is no longer for sale.");
    }

    public static function noPrice(string $title, string $currency): self
    {
        return new self("“{$title}” is not sold in {$currency}.");
    }

    /**
     * Every course in a bundle, already owned. Partial overlap is deliberately
     * NOT refused — a five-course bundle must not become unbuyable because of
     * one purchase last year — but a bundle with nothing left to deliver is a
     * refund request rather than a sale. See docs/BUNDLES.md §1.
     */
    public static function bundleFullyOwned(string $title): self
    {
        return new self("You already own every course in \"{$title}\".");
    }

    public static function alreadyOwned(string $title): self
    {
        return new self("You already have access to “{$title}”.");
    }

    public static function notPayable(): self
    {
        return new self('This order can no longer be paid.');
    }

    public function errorCode(): string
    {
        return 'checkout_rejected';
    }

    public function status(): int
    {
        return 409;
    }
}
