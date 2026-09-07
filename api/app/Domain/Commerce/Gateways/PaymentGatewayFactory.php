<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Gateways;

use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Exceptions\GatewayUnavailable;
use App\Domain\Commerce\Models\PaymentGatewayAccount;

/**
 * Resolves a provider AND the academy's account for it, together.
 *
 * Together on purpose: an implementation is useless without credentials, and
 * separating them invites a call site that builds one and forgets the other.
 */
final class PaymentGatewayFactory
{
    /**
     * @return array{0: PaymentGateway, 1: GatewayAccount}
     */
    public function for(Gateway $gateway): array
    {
        /*
         * The single guard between a test convenience and free courses.
         *
         * FakeGateway settles instantly and signs with a secret anyone
         * configuring it would choose, so reaching it in production would be
         * an open door. Refused here rather than trusted to configuration,
         * because a config mistake is exactly how it would happen.
         */
        if ($gateway === Gateway::Fake && app()->isProduction()) {
            throw GatewayUnavailable::notAvailableInProduction($gateway->value);
        }

        if (! $gateway->isAvailable()) {
            throw GatewayUnavailable::notConfigured($gateway->value);
        }

        $account = PaymentGatewayAccount::query()
            ->where('gateway', $gateway)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            throw GatewayUnavailable::notConfigured($gateway->value);
        }

        return [$this->implementation($gateway), $account->toGatewayAccount()];
    }

    private function implementation(Gateway $gateway): PaymentGateway
    {
        return match ($gateway) {
            Gateway::Fake => new FakeGateway,
            Gateway::Stripe => new StripeGateway,
            // Declared on the enum so the column never widens, but nothing
            // implements them yet — isAvailable() has already refused above.
            default => throw GatewayUnavailable::notConfigured($gateway->value),
        };
    }
}
