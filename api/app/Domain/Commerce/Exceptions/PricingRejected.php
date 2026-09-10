<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use App\Support\Exceptions\DomainException;

final class PricingRejected extends DomainException
{
    public static function unsupportedCurrency(string $currency): self
    {
        /** @var list<string> $supported */
        $supported = config('orbito.currency.supported', []);

        $exception = new self("This academy does not sell in {$currency}.");
        $exception->meta = ['supported' => $supported];

        return $exception;
    }

    /**
     * A free course has no product to hang a price on. Naming the field to
     * change beats creating a product the course's own `pricing_model` says
     * should not exist.
     */
    public static function purchasableIsFree(): self
    {
        $exception = new self('This is free, so it has nothing to price. Set it to paid first.');
        $exception->details = [[
            'field' => 'pricing_model',
            'code' => 'is_free',
            'message' => 'Change the pricing model to paid before setting a price.',
        ]];

        return $exception;
    }

    public static function notPositive(): self
    {
        return new self('A price must be greater than zero. Make it free instead.');
    }

    public static function saleNotCheaper(): self
    {
        return new self('A sale price has to be lower than the normal price.');
    }

    public static function saleWindowInverted(): self
    {
        return new self('A sale cannot end before it starts.');
    }

    public function errorCode(): string
    {
        return 'pricing_rejected';
    }

    public function status(): int
    {
        return 422;
    }
}
