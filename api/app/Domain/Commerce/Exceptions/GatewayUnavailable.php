<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The provider could not be reached, or is not connected.
 *
 * 503 rather than 500: nothing is wrong with the request, and a learner whose
 * checkout failed because Stripe was down should be told to try again.
 */
final class GatewayUnavailable extends DomainException
{
    public static function notConfigured(string $gateway): self
    {
        return new self("The {$gateway} gateway is not connected for this academy.");
    }

    public static function requestFailed(string $gateway, string $reason): self
    {
        // The provider's own message is kept: "your card was declined" is
        // useful, and Stripe does not put secrets in it.
        return new self("The {$gateway} gateway refused the request: {$reason}");
    }

    public static function notAvailableInProduction(string $gateway): self
    {
        return new self("The {$gateway} gateway cannot be used in production.");
    }

    public function errorCode(): string
    {
        return 'gateway_unavailable';
    }

    public function status(): int
    {
        return 503;
    }
}
