<?php

declare(strict_types=1);

namespace App\Http\Resources\Webhook;

use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * An endpoint — never its secret.
 *
 * `secret` is absent by construction, not filtered: there is no branch here
 * that could emit it. The create and rotate responses add it once, beside
 * this, and nothing else ever reads it back — anything the API reads back is
 * something an attacker with a session can read back too.
 *
 * @mixin WebhookEndpoint
 */
final class WebhookEndpointResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'url' => $this->url,
            'description' => $this->description,
            'events' => $this->events,
            'is_active' => $this->is_active,
            'consecutive_failures' => $this->consecutive_failures,
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'disabled_reason' => $this->disabled_reason,
            'last_delivered_at' => $this->last_delivered_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
