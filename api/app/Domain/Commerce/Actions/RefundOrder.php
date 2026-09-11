<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\RefundMethod;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Exceptions\GatewayUnavailable;
use App\Domain\Commerce\Gateways\PaymentGatewayFactory;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Str;
use Throwable;

/**
 * Gives money back on an order — claim, move, complete.
 *
 * 1. CLAIM the amount under a lock (`ClaimRefund`), written before anything
 *    leaves.
 * 2. MOVE it: through the gateway that took it, or — for a refund made in the
 *    provider's dashboard, by bank or in cash — not at all, only recorded.
 * 3. COMPLETE it (`CompleteRefund`) once the provider says it is done.
 *
 * A provider that refuses leaves the refund FAILED, which frees its amount,
 * and the refusal goes back to the admin as it came. One that accepts without
 * settling leaves it PENDING, still holding its amount.
 */
final class RefundOrder
{
    public function __construct(
        private readonly ClaimRefund $claim,
        private readonly CompleteRefund $complete,
        private readonly PaymentGatewayFactory $gateways,
    ) {}

    public function handle(
        User $actor,
        Order $order,
        int $amountMinor,
        RefundMethod $method,
        ?string $reason,
        bool $revokeAccess,
    ): Refund {
        $refund = $this->claim->handle($actor, $order, $amountMinor, $method, $reason, $revokeAccess);

        if ($method === RefundMethod::External) {
            return $this->complete->handle($refund);
        }

        $refund->loadMissing('payment');
        $payment = $refund->payment;

        try {
            if ($payment === null) {
                throw GatewayUnavailable::notConfigured('payment');
            }

            [$gateway, $account] = $this->gateways->for($payment->gateway);
            $result = $gateway->refund($payment, $refund->amount_minor, 'refund_'.$refund->uuid, $account);
        } catch (Throwable $e) {
            $refund->forceFill([
                'status' => RefundStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => Str::limit($e->getMessage(), 250, '…'),
            ])->save();

            if (! $e instanceof GatewayUnavailable) {
                report($e);

                throw GatewayUnavailable::requestFailed($payment?->gateway->value ?? 'payment', 'The refund could not be sent.');
            }

            throw $e;
        }

        if (! $result->settled) {
            $refund->forceFill(['external_id' => $result->externalId])->save();

            return $refund->refresh();
        }

        return $this->complete->handle($refund, $result->externalId);
    }
}
