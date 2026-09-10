<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Actions;

use App\Domain\Webhook\Exceptions\WebhookEndpointDisabled;
use App\Domain\Webhook\Models\WebhookDelivery;

/**
 * Sends an event again — the same event id and the same bytes, as a NEW
 * delivery with its own attempts. The original row is left as it was, so the
 * log still says what happened the first time; a receiver that already
 * processed the event recognises its id and drops the repeat.
 */
final class RedeliverWebhook
{
    public function __construct(private readonly ScheduleDelivery $schedule) {}

    public function handle(WebhookDelivery $original): WebhookDelivery
    {
        $original->loadMissing('endpoint');

        if (! $original->endpoint->is_active) {
            throw WebhookEndpointDisabled::make();
        }

        return $this->schedule->handle(
            $original->endpoint,
            $original->event_id,
            $original->topic,
            $original->body,
        );
    }
}
