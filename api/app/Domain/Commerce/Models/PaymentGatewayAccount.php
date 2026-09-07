<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Gateways\GatewayAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * One academy's credentials for one provider (ADR-13: the academy is the
 * merchant, so these are per-tenant and the platform holds none of its own).
 *
 * `credentials` and `webhook_secret` are ENCRYPTED casts, so they are
 * ciphertext at rest and never appear in a query log. They are also absent
 * from every Resource: the API says whether a gateway is connected, never what
 * with.
 *
 * @property Gateway $gateway
 * @property array<string, string>|null $credentials
 * @property string|null $webhook_secret
 * @property bool $is_active
 * @property bool $is_test_mode
 */
final class PaymentGatewayAccount extends Model
{
    protected $fillable = ['gateway', 'credentials', 'webhook_secret', 'is_active', 'is_test_mode'];

    /** Never serialise the secrets, whatever a caller does with the model. */
    protected $hidden = ['credentials', 'webhook_secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gateway' => Gateway::class,
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'is_test_mode' => 'boolean',
        ];
    }

    /**
     * The decrypted value object a gateway implementation is given.
     *
     * Deliberately not the model: an implementation that held this row could
     * save it, read the rest of it, or serialise it into an exception.
     */
    public function toGatewayAccount(): GatewayAccount
    {
        return new GatewayAccount(
            credentials: $this->credentials ?? [],
            webhookSecret: $this->webhook_secret ?? '',
            isTestMode: $this->is_test_mode,
        );
    }
}
