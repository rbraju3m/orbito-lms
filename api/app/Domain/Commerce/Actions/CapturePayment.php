<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Data\WebhookEvent;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Events\PaymentCaptured;
use App\Domain\Commerce\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Marks a payment captured, marks its order paid, and grants what was bought.
 *
 * Only ever reached from HandleWebhook, after a verified signature. It is
 * separate from that action because the checks and the consequences are
 * different jobs — and because a reconciliation sweep (polling a provider for
 * orders stuck awaiting payment) will need to arrive at exactly this point by
 * a different road. The grant itself is `GrantOrderAccess`, shared with the
 * free-order path.
 */
final class CapturePayment
{
    public function __construct(private readonly GrantOrderAccess $grant) {}

    public function handle(Payment $payment, WebhookEvent $event): Payment
    {
        $order = $payment->order;

        /*
         * The amount check.
         *
         * A provider reporting a capture smaller than the order — a partial
         * authorisation, a tampered test call, a currency mix-up — must not
         * grant access. Nothing here trusts the payload for the FIGURE; it
         * compares it against what we priced, after any coupon.
         *
         * An ABSENT figure fails too. A gateway that reports none — or a field
         * read from the wrong place, as a Checkout Session's `amount_total`
         * nearly was — must not read as "nothing to check".
         */
        if ($event->amountMinor === null || $event->currency === null) {
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => 'The gateway did not report the amount and currency it captured.',
            ])->save();

            Log::warning('Payment reported captured with no amount or currency.', ['order' => $order->uuid]);

            return $payment->refresh();
        }

        if ($event->amountMinor < $order->total_minor) {
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => 'Captured amount is less than the order total.',
            ])->save();

            Log::warning('Payment captured for less than the order total.', [
                'order' => $order->uuid,
                'expected_minor' => $order->total_minor,
                'reported_minor' => $event->amountMinor,
            ]);

            return $payment->refresh();
        }

        if ($event->currency !== strtoupper($order->currency)) {
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => 'Captured currency does not match the order.',
            ])->save();

            return $payment->refresh();
        }

        $payment->forceFill([
            'status' => PaymentStatus::Captured,
            'captured_at' => now(),
            // The money behind the handoff, when they differ — a Stripe
            // Checkout Session's PaymentIntent, which refunds name.
            'provider_payment_id' => $event->providerPaymentId ?? $payment->provider_payment_id,
        ])->save();

        $order->forceFill([
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ])->save();

        $this->grant->handle($order);

        PaymentCaptured::dispatch($payment->refresh(), $order->refresh());

        return $payment;
    }
}
