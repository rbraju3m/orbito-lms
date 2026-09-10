<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Support;

use App\Domain\Platform\Models\Tenant;
use App\Domain\Webhook\Enums\WebhookTopic;
use Illuminate\Support\Str;

/**
 * The one shape every delivery has, whatever the topic:
 *
 *   {"id", "type", "created_at", "academy": {"id", "name"}, "data": {...}}
 *
 * Encoded ONCE, here, and stored as the exact bytes signed and sent — a
 * receiver verifying the signature hashes the body it received, so re-encoding
 * on a retry would be a chance to produce different bytes for the same event.
 */
final class WebhookEnvelope
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{event_id: string, body: string}
     */
    public static function build(WebhookTopic $topic, array $data): array
    {
        $eventId = (string) Str::uuid7();
        $academy = tenancy()->tenant;

        $body = json_encode([
            'id' => $eventId,
            'type' => $topic->value,
            'created_at' => now()->utc()->toIso8601ZuluString(),
            // A receiver serving several academies has to know which one.
            'academy' => $academy instanceof Tenant
                ? ['id' => $academy->getTenantKey(), 'name' => $academy->name]
                : null,
            'data' => $data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ['event_id' => $eventId, 'body' => $body];
    }
}
