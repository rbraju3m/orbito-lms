<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Data\ProviderRefund;
use App\Domain\Commerce\Data\RefundAttention;
use App\Domain\Commerce\Enums\RefundAttentionReason;
use App\Domain\Commerce\Enums\RefundMethod;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Exceptions\RefundRejected;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Refund;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Brings the books into line with a refund the provider reports — one asked
 * for here, or one made in the provider's own dashboard.
 *
 * OURS is found by the reference we sent with the request first, and by the
 * provider's id second. Stripe can report a refund before RefundOrder has
 * stored that id, and matching on it alone would read our own refund as a
 * stranger's and count it twice. Either way the match is scoped to the payment
 * HandleWebhook resolved from the id WE issued — never to anything the payload
 * alone asserts.
 *
 * Only a terminal report moves anything, and only a PENDING row moves. Reports
 * arrive in any order, so a late `pending` after `succeeded` is stale, not a
 * reversal. The two contradictions a report can carry — money given back on a
 * refund recorded here as failed, or a completed refund the provider has since
 * failed — are left for a person: undoing either re-decides access and revenue
 * that nobody asked this class to re-decide.
 *
 * Returns null when the report is settled here. A RefundAttention says why it
 * is not; HandleWebhook stores it on the event, and the refund-reports screen
 * shows it to somebody who can refund.
 */
final class ReconcileProviderRefund
{
    public function __construct(
        private readonly ClaimRefund $claim,
        private readonly CompleteRefund $complete,
        private readonly FailRefund $fail,
    ) {}

    public function handle(Payment $payment, ProviderRefund $report): ?RefundAttention
    {
        $refund = $this->ours($payment, $report);

        return $refund === null
            ? $this->recordFromProvider($payment, $report)
            : $this->settle($refund, $report);
    }

    private function ours(Payment $payment, ProviderRefund $report): ?Refund
    {
        if ($report->reference !== null) {
            $refund = Refund::query()
                ->where('payment_id', $payment->id)
                ->where('uuid', $report->reference)
                ->first();

            if ($refund !== null) {
                return $refund;
            }
        }

        return Refund::query()
            ->where('payment_id', $payment->id)
            ->where('external_id', $report->externalId)
            ->first();
    }

    private function settle(Refund $refund, ProviderRefund $report): ?RefundAttention
    {
        if ($report->amountMinor !== $refund->amount_minor || $report->currency !== $refund->currency) {
            return $this->needsAPerson(RefundAttentionReason::DifferentAmount, $report, $refund);
        }

        return match ($report->status) {
            RefundStatus::Pending => $this->noteExternalId($refund, $report),
            RefundStatus::Completed => match ($refund->status) {
                RefundStatus::Pending => $this->completed($refund, $report),
                RefundStatus::Completed => null,
                RefundStatus::Failed => $this->needsAPerson(RefundAttentionReason::RefundedAfterFailure, $report, $refund),
            },
            RefundStatus::Failed => match ($refund->status) {
                RefundStatus::Pending => $this->failed($refund, $report),
                RefundStatus::Failed => null,
                RefundStatus::Completed => $this->needsAPerson(RefundAttentionReason::FailedAfterCompletion, $report, $refund),
            },
        };
    }

    /**
     * A refund made in the provider's dashboard. Claimed like any other — the
     * same lock, the same split, the same "only what is left" — held while the
     * provider says pending, and completed when it says so. A full one takes
     * away what the order granted, as the refund dialog's default does; a
     * partial one never touches access.
     */
    private function recordFromProvider(Payment $payment, ProviderRefund $report): ?RefundAttention
    {
        // Nothing moved, so there is nothing to record.
        if ($report->status === RefundStatus::Failed) {
            return null;
        }

        $order = Order::query()->findOrFail($payment->order_id);

        if ($report->currency !== $order->currency) {
            return $this->needsAPerson(RefundAttentionReason::WrongCurrency, $report, null);
        }

        try {
            $refund = $this->claim->handle(
                actor: null,
                order: $order,
                amountMinor: $report->amountMinor,
                method: RefundMethod::Gateway,
                reason: 'Refunded through '.$payment->gateway->label().'.',
                revokeAccess: true,
                payment: $payment,
                externalId: $report->externalId,
            );
        } catch (RefundRejected|UniqueConstraintViolationException) {
            /*
             * Either another event about this same refund recorded it a moment
             * ago — refund.created and refund.updated can arrive together, and
             * the order lock made this one wait for the other to commit — or it
             * really is more than the order has left (recorded by hand as well,
             * say). A LOCKING read sees the row a plain one could miss under
             * this transaction's older snapshot.
             */
            $recorded = Refund::query()->where('external_id', $report->externalId)->lockForUpdate()->first();

            return $recorded === null
                ? $this->needsAPerson(RefundAttentionReason::MoreThanLeft, $report, null)
                : $this->settle($recorded, $report);
        }

        return $report->status === RefundStatus::Completed
            ? $this->completed($refund, $report)
            : null;
    }

    private function completed(Refund $refund, ProviderRefund $report): null
    {
        $this->complete->handle($refund, $report->externalId);

        return null;
    }

    private function failed(Refund $refund, ProviderRefund $report): null
    {
        $this->fail->handle($refund, $report->failureReason ?? 'Reported failed by the provider.');

        return null;
    }

    /** Still pending there and here. Keep the provider's id, move nothing. */
    private function noteExternalId(Refund $refund, ProviderRefund $report): null
    {
        if ($refund->status === RefundStatus::Pending && $refund->external_id === null) {
            $refund->forceFill(['external_id' => $report->externalId])->save();
        }

        return null;
    }

    private function needsAPerson(RefundAttentionReason $reason, ProviderRefund $report, ?Refund $refund): RefundAttention
    {
        // Ids and amounts only — never the payload (CLAUDE.md § Security checklist).
        Log::warning("Provider refund left for a person: {$reason->label()}.", [
            'refund' => $refund?->uuid,
            'provider_refund' => $report->externalId,
            'amount_minor' => $report->amountMinor,
            'currency' => $report->currency,
            'reported_status' => $report->status->value,
        ]);

        return new RefundAttention($reason, $report, $refund?->uuid);
    }
}
