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

    /**
     * A place at an event with none left.
     *
     * Refused at the basket and again at checkout, and NOT at the moment the
     * payment lands: by then the money has moved, and refusing a paid
     * registrant is worse than a room with one extra person in it. See
     * `GrantOrderAccess::grantWebinar()`.
     */
    public static function webinarFull(string $title): self
    {
        return new self("“{$title}” has no places left.");
    }

    /** Selling a ticket to something that has already happened. */
    public static function webinarOver(string $title): self
    {
        return new self("“{$title}” has already taken place.");
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
