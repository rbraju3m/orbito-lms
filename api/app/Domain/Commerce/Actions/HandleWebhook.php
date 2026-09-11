<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Gateways\PaymentGatewayFactory;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\PaymentEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The only thing in the system that can turn an order into access — and the
 * only way a provider can tell us it gave money back.
 *
 * Order of operations is the whole design, and each step exists because
 * skipping it is exploitable:
 *
 *  1. VERIFY the signature first. Nothing in the payload may be read as fact
 *     before this, because until it passes the payload is attacker-controlled.
 *  2. RECORD the event under a unique (gateway, external_event_id). A replayed
 *     delivery collides here and stops, which is what makes a second delivery
 *     a no-op rather than a second enrolment.
 *  3. MATCH the payment by the external id WE stored at handoff — never by
 *     anything the payload alone asserts about which order it is.
 *  4. CHECK the amount and currency against the order. A provider reporting a
 *     smaller capture than the order total must not grant access.
 *  5. Only then capture.
 *  6. RECONCILE any refunds the event reports, against the payment matched in
 *     step 3 (`ReconcileProviderRefund`). One that cannot be settled leaves
 *     the event unprocessed, for a person.
 */
final class HandleWebhook
{
    public function __construct(
        private readonly PaymentGatewayFactory $gateways,
        private readonly CapturePayment $capture,
        private readonly ReconcileProviderRefund $refunds,
    ) {}

    public function handle(Request $request, Gateway $gateway): PaymentEvent
    {
        [$implementation, $account] = $this->gateways->for($gateway);

        // Throws on a bad signature. Nothing below runs for a forged delivery,
        // and the rejection is deliberately not recorded against a payment —
        // we have no verified reason to associate it with one.
        $event = $implementation->verifyWebhook($request, $account);

        $existing = PaymentEvent::query()
            ->where('gateway', $gateway)
            ->where('external_event_id', $event->id)
            ->first();

        // A replay. Already handled, so answer 2xx and do nothing — retrying
        // is normal provider behaviour, not an error.
        if ($existing !== null) {
            return $existing;
        }

        $payment = $event->externalPaymentId === null
            ? null
            : Payment::query()
                ->where('gateway', $gateway)
                ->where('external_id', $event->externalPaymentId)
                ->first();

        return DB::transaction(function () use ($event, $gateway, $payment): PaymentEvent {
            $record = PaymentEvent::create([
                'payment_id' => $payment?->id,
                'gateway' => $gateway,
                'external_event_id' => $event->id,
                'type' => $event->type,
                'payload' => $event->payload,
                'signature_verified' => true,
                'received_at' => now(),
            ]);

            // A verified event about a payment we have no record of. Stored so
            // an operator can see it, acted on never: granting access from an
            // id we never issued would be the forgery this design refuses.
            if ($payment === null) {
                return $record;
            }

            if ($event->isSuccess() && $payment->status->isOpen()) {
                $this->capture->handle($payment, $event);
            }

            if ($event->isFailure() && $payment->status->isOpen()) {
                $payment->forceFill([
                    'status' => PaymentStatus::Failed,
                    'failed_at' => now(),
                    'failure_reason' => 'Reported failed by the gateway.',
                ])->save();
            }

            /*
             * Settled only if every refund the event names could be. One that
             * cannot — more than is left, a refund reversed after we completed
             * it — stays unprocessed: stored for a person, never guessed at.
             * Inside this transaction on purpose: if reconciling throws, the
             * event is not recorded either, so the provider's retry is the
             * recovery rather than a replay we would ignore.
             */
            $settled = true;

            foreach ($event->refunds as $refund) {
                $settled = $this->refunds->handle($payment, $refund) && $settled;
            }

            if ($settled) {
                $record->forceFill(['processed_at' => now()])->save();
            }

            return $record;
        });
    }
}
