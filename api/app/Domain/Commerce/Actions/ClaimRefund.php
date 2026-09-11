<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Enums\RefundMethod;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Exceptions\RefundRejected;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Commerce\Support\RefundSplit;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stakes a claim on part of an order's money, BEFORE any of it moves.
 *
 * Behind a lock on the order's row: what is still refundable is counted and
 * the pending refund written in one transaction, so two admins — or one
 * double-click — cannot both give back the last of it (§ Patterns
 * established in Phase 9: count and insert in ONE transaction). A pending
 * refund holds its amount; a failed one lets it go.
 *
 * Written before the gateway is called, as a payment row is written before
 * the handoff: a process that dies mid-call leaves a row somebody can find.
 */
final class ClaimRefund
{
    public function __construct(private readonly RefundSplit $split) {}

    /**
     * `$actor` is null when nobody here asked: the provider reported a refund
     * made in its own dashboard (ReconcileProviderRefund), which also names the
     * `$payment` it went back through and the provider's `$externalId` for it.
     */
    public function handle(
        ?User $actor,
        Order $order,
        int $amountMinor,
        RefundMethod $method,
        ?string $reason,
        bool $revokeAccess,
        ?Payment $payment = null,
        ?string $externalId = null,
    ): Refund {
        return DB::transaction(function () use ($actor, $order, $amountMinor, $method, $reason, $revokeAccess, $payment, $externalId): Refund {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->status->isRefundable()) {
                throw RefundRejected::notPaid();
            }

            $claimed = (int) $order->refunds()
                ->whereIn('status', [RefundStatus::Pending, RefundStatus::Completed])
                ->sum('amount_minor');
            $refundable = $order->total_minor - $claimed;

            if ($refundable <= 0) {
                throw RefundRejected::nothingLeft();
            }

            if ($amountMinor > $refundable) {
                throw RefundRejected::tooMuch($refundable, $order->currency);
            }

            if ($method === RefundMethod::Gateway) {
                $payment ??= $order->payments()
                    ->where('status', PaymentStatus::Captured)
                    ->latest('captured_at')
                    ->first();

                if ($payment === null || $payment->order_id !== $order->id) {
                    throw RefundRejected::noPayment();
                }
            } else {
                $payment = null;
            }

            $refund = Refund::create([
                'order_id' => $order->id,
                'payment_id' => $payment?->id,
                'amount_minor' => $amountMinor,
                'currency' => $order->currency,
                'method' => $method,
                'status' => RefundStatus::Pending,
                'reason' => $reason,
                // Only the refund that empties the order can take access away,
                // and only if the admin did not choose otherwise.
                'revokes_access' => $revokeAccess && $amountMinor === $refundable,
                'external_id' => $externalId,
                'requested_by' => $actor?->id,
            ]);

            foreach ($this->split->split($order, $amountMinor) as $orderItemId => $share) {
                $line = $refund->lines()->create([
                    'order_item_id' => $orderItemId,
                    'amount_minor' => $share['amount'],
                ]);

                foreach ($share['allocations'] as $courseId => $amount) {
                    $line->allocations()->create(['course_id' => $courseId, 'amount_minor' => $amount]);
                }
            }

            return $refund;
        });
    }
}
