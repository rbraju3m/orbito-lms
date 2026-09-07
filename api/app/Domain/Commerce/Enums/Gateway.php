<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * The gateways the platform can speak to.
 *
 * Declared in full so the column never widens, even though only `fake` and
 * `stripe` are reachable today. Each academy connects its OWN account (ADR-13):
 * the platform holds no merchant relationship and never touches learner money.
 */
enum Gateway: string
{
    /**
     * A gateway that settles instantly and verifies its own signatures. For
     * local development and for the tests that prove the money path — NOT
     * usable in production, which `PaymentGatewayFactory` enforces.
     */
    case Fake = 'fake';
    case Stripe = 'stripe';
    case PayPal = 'paypal';
    case SslCommerz = 'sslcommerz';
    case Bkash = 'bkash';
    case Nagad = 'nagad';

    public function isAvailable(): bool
    {
        return match ($this) {
            self::Fake, self::Stripe => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Fake => 'Test gateway',
            self::Stripe => 'Stripe',
            self::PayPal => 'PayPal',
            self::SslCommerz => 'SSLCommerz',
            self::Bkash => 'bKash',
            self::Nagad => 'Nagad',
        };
    }
}
