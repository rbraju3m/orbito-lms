<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Whether a gateway is connected — never HOW.
 *
 * `credentials` and `webhook_secret` are absent by construction rather than
 * filtered out: there is no branch here that could ever emit them, so no
 * future edit can accidentally re-enable one. An admin who has forgotten a
 * secret re-enters it; the API will not read it back to them, because
 * anything the API will read back is something an attacker with a session can
 * read back too.
 *
 * @mixin PaymentGatewayAccount
 */
final class PaymentGatewayAccountResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'gateway' => $this->gateway->value,
            'label' => $this->gateway->label(),
            'is_connected' => $this->credentials !== null && $this->credentials !== [],
            'has_webhook_secret' => $this->webhook_secret !== null && $this->webhook_secret !== '',
            'is_active' => $this->is_active,
            'is_test_mode' => $this->is_test_mode,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A gateway the academy has NOT connected, in the same shape.
     *
     * The list has to include these — an admin cannot connect Stripe from a
     * list that only shows what is already connected.
     *
     * @return array<string, mixed>
     */
    public static function unconnected(Gateway $gateway): array
    {
        return [
            'gateway' => $gateway->value,
            'label' => $gateway->label(),
            'is_connected' => false,
            'has_webhook_secret' => false,
            'is_active' => false,
            'is_test_mode' => true,
            'updated_at' => null,
        ];
    }
}
