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

    /**
     * The events this gateway's webhook endpoint must be subscribed to. The
     * Payments screen lists them beside the endpoint's URL, and every one is a
     * type HandleWebhook acts on (PaymentGatewayAdminTest), so the screen can
     * never ask an academy to send something the server then ignores.
     *
     * @return list<string>
     */
    public function webhookEvents(): array
    {
        return match ($this) {
            self::Fake => ['payment.captured', 'payment.failed'],
            self::Stripe => ['payment_intent.succeeded', 'payment_intent.payment_failed'],
            default => [],
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
