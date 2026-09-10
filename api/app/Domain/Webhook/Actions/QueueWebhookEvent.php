<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Actions;

use App\Domain\Webhook\Enums\WebhookTopic;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Domain\Webhook\Support\WebhookEnvelope;
use Closure;
use Throwable;

/**
 * One domain event, fanned out to every endpoint that asked for it.
 *
 * Runs in the request, deliberately: the payload is a frozen MESSAGE about the
 * moment the event fired, and a queued listener re-reads its models from the
 * database — by then an enrolment may have been suspended, and the webhook
 * would describe a state that was not the one that happened. Only the HTTP is
 * queued (`DeliverWebhook`).
 *
 * The data is a closure, built only if somebody is listening: most academies
 * have no endpoints, and they should pay one indexed query per event, not the
 * lookups a payload needs.
 *
 * NEVER throws into the caller. A webhook observes the system, like analytics;
 * a broken integration must not break somebody's enrolment (§ Patterns
 * established in Phase 13).
 */
final class QueueWebhookEvent
{
    public function __construct(private readonly ScheduleDelivery $schedule) {}

    /**
     * @param  Closure(): array<string, mixed>  $data
     * @return int deliveries queued
     */
    public function handle(WebhookTopic $topic, Closure $data): int
    {
        try {
            $endpoints = WebhookEndpoint::query()
                ->where('is_active', true)
                ->whereJsonContains('events', $topic->value)
                ->get();

            if ($endpoints->isEmpty()) {
                return 0;
            }

            // One event, one id and one body — shared by every endpoint.
            $envelope = WebhookEnvelope::build($topic, $data());

            foreach ($endpoints as $endpoint) {
                $this->schedule->handle($endpoint, $envelope['event_id'], $topic->value, $envelope['body']);
            }

            return $endpoints->count();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
