<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

/**
 * What a provider said when asked to give money back.
 *
 * `settled` false means the provider accepted the refund but has not finished
 * it (Stripe reports some payment methods as `pending`). The refund stays
 * pending until a provider refund webhook confirms it — and those are not
 * handled yet, so it is left for an operator (docs/REFUNDS.md §6).
 */
final readonly class GatewayRefund
{
    public function __construct(
        public string $externalId,
        public bool $settled,
    ) {}
}
