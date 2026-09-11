<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

use App\Domain\Commerce\Enums\RefundStatus;

/**
 * A refund, as the provider reports it in a verified webhook.
 *
 * Built only by a gateway's verifyWebhook(), so it is as trustworthy as the
 * signature — but it is a REPORT, not an instruction: ReconcileProviderRefund
 * decides what, if anything, it changes here.
 */
final readonly class ProviderRefund
{
    public function __construct(
        /** The provider's own refund id (Stripe `re_…`). */
        public string $externalId,
        public int $amountMinor,
        public string $currency,
        /** The provider's status, mapped onto ours. */
        public RefundStatus $status,
        /**
         * Our refund's uuid, when the refund was asked for here: it travels to
         * the provider with the request and comes back on every report, so a
         * report finds its row before the provider's id is stored on it. Null
         * for a refund made in the provider's own dashboard.
         */
        public ?string $reference = null,
        public ?string $failureReason = null,
    ) {}
}
