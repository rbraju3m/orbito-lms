<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Actions;

use App\Domain\Webhook\Enums\WebhookTopic;
use App\Domain\Webhook\Exceptions\WebhookEndpointDisabled;
use App\Domain\Webhook\Models\WebhookDelivery;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Domain\Webhook\Support\WebhookEnvelope;

/**
 * A `ping`, so an academy can see its receiver answer before real data flows.
 * Signed and logged exactly like a real delivery — that is the point of it.
 */
final class SendTestWebhook
{
    public function __construct(private readonly ScheduleDelivery $schedule) {}

    public function handle(WebhookEndpoint $endpoint): WebhookDelivery
    {
        if (! $endpoint->is_active) {
            throw WebhookEndpointDisabled::make();
        }

        $envelope = WebhookEnvelope::build(WebhookTopic::Ping, [
            'message' => 'A test event from Orbito. If you can read this, your endpoint is receiving.',
        ]);

        return $this->schedule->handle($endpoint, $envelope['event_id'], WebhookTopic::Ping->value, $envelope['body']);
    }
}
