<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Jobs;

use App\Domain\Webhook\Actions\AttemptDelivery;
use App\Domain\Webhook\Actions\SettleDelivery;
use App\Domain\Webhook\Enums\DeliveryStatus;
use App\Domain\Webhook\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Sends one delivery, and schedules the next attempt when it fails.
 *
 * The attempt count that matters is the delivery's own (`attempts`), not the
 * queue's: it is what the log shows, and it survives a worker restart. Retries
 * are a `release()`, which on the sync queue tests run under is a no-op —
 * so a test drives the next attempt by running the job again, instead of the
 * sync driver retrying eight times inside the request that fired the event.
 *
 * Dispatched from inside an academy, so the tenancy queue bootstrapper opens
 * that academy again in the worker.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Seconds to wait after attempt n fails: 1 min, 5 min, 30 min, 2 h, 6 h,
     * 12 h, 24 h — eight attempts across ~45 hours. Long enough to ride out a
     * receiver's weekend outage; short enough that "yesterday's enrolments
     * arrived today" is the worst case anybody explains.
     */
    public const BACKOFF = [60, 300, 1_800, 7_200, 21_600, 43_200, 86_400];

    public int $tries;

    /** One HTTP call, capped at the configured timeout, with room to record it. */
    public int $timeout = 60;

    public function __construct(public readonly int $deliveryId)
    {
        $this->tries = (int) config('orbito.webhooks.max_attempts');
    }

    public function handle(AttemptDelivery $attempt, SettleDelivery $settle): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        // Deleted with its endpoint, or already settled by an earlier run.
        if ($delivery === null || $delivery->status !== DeliveryStatus::Pending) {
            return;
        }

        // Switched off while this waited: an administrator who turns an
        // endpoint off means "stop sending my data there", retries included.
        if (! $delivery->endpoint->is_active) {
            $settle->abandoned($delivery, 'The endpoint was switched off before this could be sent.');

            return;
        }

        if ($attempt->handle($delivery)) {
            $settle->succeeded($delivery);

            return;
        }

        if ($delivery->attempts >= (int) config('orbito.webhooks.max_attempts')) {
            $settle->exhausted($delivery);

            return;
        }

        $delay = self::BACKOFF[min($delivery->attempts - 1, count(self::BACKOFF) - 1)];
        $delivery->forceFill(['next_attempt_at' => now()->addSeconds($delay)])->save();

        $this->release($delay);
    }

    /** The queue gave up — a crash or a timeout, not an answer we recorded. */
    public function failed(Throwable $e): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery !== null && $delivery->status === DeliveryStatus::Pending) {
            app(SettleDelivery::class)->exhausted($delivery);
        }
    }
}
