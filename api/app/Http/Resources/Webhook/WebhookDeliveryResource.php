<?php

declare(strict_types=1);

namespace App\Http\Resources\Webhook;

use App\Domain\Webhook\Models\WebhookDelivery;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One delivery, as its log line: what was sent, how often we tried, and what
 * the receiver said. For an academy debugging its own integration, so it
 * carries the payload — the endpoint's owner chose to receive exactly this.
 *
 * @mixin WebhookDelivery
 */
final class WebhookDeliveryResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'event_id' => $this->event_id,
            'topic' => $this->topic,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'attempts' => $this->attempts,
            'max_attempts' => (int) config('orbito.webhooks.max_attempts'),
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'last_attempt_at' => $this->last_attempt_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            'error' => $this->error,
            'duration_ms' => $this->duration_ms,
            'payload' => $this->payload(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
