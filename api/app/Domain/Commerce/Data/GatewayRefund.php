<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

/**
 * What a provider said when asked to give money back.
 *
 * `settled` false means the provider accepted the refund but has not finished
 * it (Stripe reports some payment methods as `pending`). The refund stays
 * pending, holding its amount, until the provider's refund webhook settles it
 * (ReconcileProviderRefund).
 */
final readonly class GatewayRefund
{
    public function __construct(
        public string $externalId,
        public bool $settled,
    ) {}
}
