<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

use App\Domain\Commerce\Enums\RefundAttentionReason;

/**
 * A provider's refund report that needs a person, and why. Stored on the
 * webhook event (`payment_events.attention`) so the refund-reports screen can
 * say what happened without re-reading the provider's payload.
 */
final readonly class RefundAttention
{
    public function __construct(
        public RefundAttentionReason $reason,
        public ProviderRefund $report,
        /** Our refund it names, when there is one. */
        public ?string $refundUuid = null,
    ) {}

    /**
     * @return array{reason: string, provider_refund_id: string, amount_minor: int, currency: string, reported_status: string, refund: string|null}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason->value,
            'provider_refund_id' => $this->report->externalId,
            'amount_minor' => $this->report->amountMinor,
            'currency' => $this->report->currency,
            'reported_status' => $this->report->status->value,
            'refund' => $this->refundUuid,
        ];
    }
}
