<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Actions;

use App\Domain\Webhook\Enums\DeliveryStatus;
use App\Domain\Webhook\Jobs\DeliverWebhook;
use App\Domain\Webhook\Models\WebhookDelivery;
use App\Domain\Webhook\Models\WebhookEndpoint;

/**
 * Records a delivery and queues its first attempt.
 *
 * After COMMIT: most events fire inside the transaction of the Action that
 * caused them, and a job that ran before it committed would send a webhook
 * about an enrolment that was then rolled back.
 */
final class ScheduleDelivery
{
    public function handle(WebhookEndpoint $endpoint, string $eventId, string $topic, string $body): WebhookDelivery
    {
        $delivery = WebhookDelivery::create([
            'endpoint_id' => $endpoint->id,
            'event_id' => $eventId,
            'topic' => $topic,
            'body' => $body,
            'status' => DeliveryStatus::Pending,
            'attempts' => 0,
        ]);

        DeliverWebhook::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }
}
