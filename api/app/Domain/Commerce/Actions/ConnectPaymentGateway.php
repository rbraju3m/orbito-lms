<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Exceptions\GatewayUnavailable;
use App\Domain\Commerce\Models\PaymentGatewayAccount;

/**
 * Stores one academy's credentials for one provider (ADR-13).
 *
 * The academy is the merchant of record, so this is the only way money can
 * ever move: until an academy runs this, `PaymentGatewayFactory` refuses and
 * checkout cannot start.
 *
 * Credentials are write-only from the API's point of view. They are encrypted
 * by the model's casts, never returned by any Resource, and a partial update
 * that omits them KEEPS what is stored rather than blanking it — otherwise
 * toggling test mode would silently disconnect the gateway.
 */
final class ConnectPaymentGateway
{
    /**
     * @param  array<string, string>|null  $credentials
     */
    public function handle(
        Gateway $gateway,
        ?array $credentials = null,
        ?string $webhookSecret = null,
        ?bool $isActive = null,
        ?bool $isTestMode = null,
    ): PaymentGatewayAccount {
        // The same guard PaymentGatewayFactory applies at spend time, applied
        // again at configure time: an academy should be told it cannot use a
        // provider when it tries to connect one, not at a learner's checkout.
        if ($gateway === Gateway::Fake && app()->isProduction()) {
            throw GatewayUnavailable::notAvailableInProduction($gateway->value);
        }

        if (! $gateway->isAvailable()) {
            throw GatewayUnavailable::notConfigured($gateway->value);
        }

        $account = PaymentGatewayAccount::firstOrNew(['gateway' => $gateway]);

        if ($credentials !== null) {
            $account->credentials = $credentials;
        }

        if ($webhookSecret !== null) {
            $account->webhook_secret = $webhookSecret;
        }

        if ($isTestMode !== null) {
            $account->is_test_mode = $isTestMode;
        }

        /*
         * Activation is refused until there is something to activate. An
         * active account with no credentials fails at the gateway call
         * instead — after the learner has committed to buying.
         */
        if ($isActive === true && ($account->credentials === null || $account->credentials === [])) {
            throw GatewayUnavailable::notConfigured($gateway->value);
        }

        if ($isActive !== null) {
            $account->is_active = $isActive;
        }

        $account->save();

        return $account->refresh();
    }
}
