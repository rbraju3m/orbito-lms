<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Listeners;

use App\Domain\Analytics\Actions\RecordEvent;
use App\Domain\Analytics\Data\EventData;
use App\Domain\Analytics\Enums\EventName;
use App\Domain\Commerce\Events\PaymentCaptured;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Money, as the log sees it.
 *
 * Raised from CAPTURE, never from the order being placed: an order is an
 * intention and a capture is a fact, and a revenue chart built on intentions
 * reports money nobody paid.
 *
 * The amount is copied in minor units with its currency (ADR-04). It is a
 * SNAPSHOT — a later refund does not rewrite this row, because it did happen;
 * refunds get their own name when there is an event to hang one on.
 */
final class RecordCommerceEvents implements ShouldQueue
{
    public function __construct(private readonly RecordEvent $record) {}

    public function handle(PaymentCaptured $event): void
    {
        $payment = $event->payment;
        $order = $event->order;

        $this->record->handle(new EventData(
            name: EventName::PaymentCompleted,
            occurredAt: $payment->captured_at,
            actorId: $order->user_id,
            subjectType: $order->getMorphClass(),
            subjectId: $order->id,
            properties: [
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'gateway' => $payment->gateway->value,
                'order_number' => $order->number,
            ],
        ));
    }
}
