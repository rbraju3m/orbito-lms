<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Actions;

use App\Domain\Webhook\Enums\DeliveryStatus;
use App\Domain\Webhook\Models\WebhookDelivery;
use App\Domain\Webhook\Models\WebhookEndpoint;

/**
 * How a delivery ends, and what that says about its endpoint.
 *
 * An endpoint is judged on DELIVERIES that ran out of attempts, not on single
 * failed attempts: one blip is what the retries are for, while five events in
 * a row that failed for ~45 hours each is an address nobody is answering.
 */
final class SettleDelivery
{
    public function succeeded(WebhookDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::Succeeded,
            'delivered_at' => now(),
            'next_attempt_at' => null,
        ])->save();

        WebhookEndpoint::query()->whereKey($delivery->endpoint_id)->update([
            'consecutive_failures' => 0,
            'last_delivered_at' => now(),
        ]);
    }

    public function exhausted(WebhookDelivery $delivery): void
    {
        $delivery->forceFill(['status' => DeliveryStatus::Failed, 'next_attempt_at' => null])->save();

        // An atomic increment: two deliveries giving up at once must both count.
        WebhookEndpoint::query()->whereKey($delivery->endpoint_id)->increment('consecutive_failures');

        $endpoint = WebhookEndpoint::query()->find($delivery->endpoint_id);
        $threshold = (int) config('orbito.webhooks.disable_after_failures');

        if ($endpoint !== null && $endpoint->is_active && $endpoint->consecutive_failures >= $threshold) {
            $endpoint->forceFill([
                'is_active' => false,
                'disabled_at' => now(),
                'disabled_reason' => "Switched off automatically after {$endpoint->consecutive_failures} deliveries in a row failed every attempt.",
            ])->save();
        }
    }

    /**
     * Stopped without being judged — the endpoint was switched off while this
     * waited. Not the receiver's failure, so it does not count against it.
     */
    public function abandoned(WebhookDelivery $delivery, string $reason): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::Failed,
            'next_attempt_at' => null,
            'error' => $reason,
        ])->save();
    }
}
