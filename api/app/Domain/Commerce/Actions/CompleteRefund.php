<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Events\RefundIssued;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Refund;
use Illuminate\Support\Facades\DB;

/**
 * The money is back: mark the refund done, move the order's figures and
 * status, and — only when this refund emptied the order and the admin did not
 * choose otherwise — take away what the order granted.
 *
 * Idempotent: completing a refund that is no longer pending changes nothing,
 * so a provider confirming twice (a future refund webhook) is harmless.
 */
final class CompleteRefund
{
    public function __construct(private readonly RevokeOrderAccess $revoke) {}

    public function handle(Refund $refund, ?string $externalId = null): Refund
    {
        $outcome = DB::transaction(function () use ($refund, $externalId): ?array {
            $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
            $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($refund->status !== RefundStatus::Pending) {
                return null;
            }

            $refund->forceFill([
                'status' => RefundStatus::Completed,
                'completed_at' => now(),
                'external_id' => $externalId ?? $refund->external_id,
            ])->save();

            $refunded = $order->refunded_minor + $refund->amount_minor;
            $full = $refunded >= $order->total_minor;

            $order->forceFill([
                'refunded_minor' => $refunded,
                'status' => $full ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded,
            ])->save();

            return ['refund' => $refund, 'order' => $order, 'full' => $full];
        });

        if ($outcome === null) {
            return $refund->refresh();
        }

        if ($outcome['full'] && $outcome['refund']->revokes_access) {
            $this->revoke->handle($outcome['order']);
        }

        RefundIssued::dispatch($outcome['refund'], $outcome['order'], $outcome['full']);

        return $outcome['refund'];
    }
}
