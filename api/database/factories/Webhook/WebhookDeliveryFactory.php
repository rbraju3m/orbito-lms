<?php

declare(strict_types=1);

namespace Database\Factories\Webhook;

use App\Domain\Webhook\Enums\DeliveryStatus;
use App\Domain\Webhook\Models\WebhookDelivery;
use App\Domain\Webhook\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookDelivery>
 */
final class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $eventId = (string) Str::uuid7();

        return [
            'uuid' => (string) Str::uuid7(),
            'endpoint_id' => WebhookEndpoint::factory(),
            'event_id' => $eventId,
            'topic' => 'ping',
            'body' => (string) json_encode(['id' => $eventId, 'type' => 'ping', 'data' => []]),
            'status' => DeliveryStatus::Pending,
            'attempts' => 0,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Failed,
            'attempts' => 8,
            'response_status' => 500,
            'last_attempt_at' => now(),
        ]);
    }
}
