<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Data\GatewayHandoff;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Commerce\Gateways\PaymentGatewayFactory;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;

/**
 * Hands a priced order to the academy's gateway.
 *
 * Note what this does NOT do: grant anything. It moves the order to
 * `awaiting_payment` and returns somewhere to send the learner. Access waits
 * for a verified webhook, however convincing the redirect back looks (ADR-05).
 */
final class InitiatePayment
{
    public function __construct(private readonly PaymentGatewayFactory $gateways) {}

    public function handle(Order $order, Gateway $gateway): GatewayHandoff
    {
        if (! $order->status->isPayable()) {
            throw CheckoutRejected::notPayable();
        }

        [$implementation, $account] = $this->gateways->for($gateway);

        /*
         * The payment row is written BEFORE the handoff and updated after.
         *
         * If the process dies mid-call there is a row with no external id,
         * which the reconciliation sweep can find. The alternative — writing
         * it only on success — loses the fact that money may have moved.
         */
        $payment = Payment::create([
            'order_id' => $order->id,
            'gateway' => $gateway,
            'status' => PaymentStatus::Initiated,
            'currency' => $order->currency,
            'amount_minor' => $order->total_minor,
            'initiated_at' => now(),
        ]);

        $handoff = $implementation->handoff($order, $account);

        $payment->forceFill([
            'external_id' => $handoff->externalId,
            'status' => PaymentStatus::Pending,
        ])->save();

        $order->forceFill(['status' => OrderStatus::AwaitingPayment])->save();

        return $handoff;
    }
}
