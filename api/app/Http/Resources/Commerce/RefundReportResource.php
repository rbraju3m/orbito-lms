<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Enums\RefundAttentionReason;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Models\PaymentEvent;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A refund report the webhook left for a person: what happened, what to check,
 * what the provider reported, and the order it concerns. Never the payload —
 * it is the provider's raw object, and nothing on this screen needs it.
 *
 * @mixin PaymentEvent
 */
final class RefundReportResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->payment?->order;

        return [
            'id' => $this->id,
            'gateway' => $this->gateway->value,
            'gateway_label' => $this->gateway->label(),
            'event_type' => $this->type,
            'received_at' => $this->received_at->toIso8601String(),
            'items' => array_map(self::item(...), $this->attention ?? []),
            'order' => $order === null ? null : ['id' => $order->uuid, 'number' => $order->number],
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_note' => $this->resolution_note,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function item(array $item): array
    {
        $reason = RefundAttentionReason::tryFrom((string) ($item['reason'] ?? ''));
        $status = RefundStatus::tryFrom((string) ($item['reported_status'] ?? ''));

        return [
            'reason' => $reason?->value,
            'reason_label' => $reason?->label() ?? 'Needs a person',
            'advice' => $reason?->advice(),
            'provider_refund_id' => $item['provider_refund_id'] ?? null,
            'amount_minor' => $item['amount_minor'] ?? null,
            'currency' => $item['currency'] ?? null,
            'reported_status' => $status?->value,
            'reported_status_label' => $status?->label(),
        ];
    }
}
