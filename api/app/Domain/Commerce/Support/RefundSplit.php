<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Support;

use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Commerce\Models\RefundLine;
use App\Domain\Commerce\Models\RefundLineAllocation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which lines a refund gives back from — and, for a bundle line, which of
 * its courses.
 *
 * Weighted by what each line has LEFT, not by what it cost: after one partial
 * refund the lines no longer hold money in their original proportions, and
 * weighting by the original would one day refund a line past what it has.
 * By what is left, a run of partial refunds that adds up to the order lands
 * on exactly zero everywhere. Largest remainder (`RevenueAllocator`) all the
 * way down, so every level sums to the one above it exactly — the invariant
 * the revenue reports rely on (docs/REFUNDS.md §4).
 */
final class RefundSplit
{
    public function __construct(private readonly RevenueAllocator $allocator) {}

    /**
     * @return array<int, array{amount: int, allocations: array<int, int>}> order item id => share and, for a bundle, course id => share
     */
    public function split(Order $order, int $amountMinor): array
    {
        $order->loadMissing('items.allocations');

        $given = RefundLine::query()
            ->whereIn('order_item_id', $order->items->pluck('id'))
            ->whereHas('refund', self::claiming(...))
            ->groupBy('order_item_id')
            ->selectRaw('order_item_id, SUM(amount_minor) as given')
            ->toBase()
            ->pluck('given', 'order_item_id');

        $left = [];

        foreach ($order->items as $item) {
            $remaining = $item->total_minor - (int) ($given[$item->id] ?? 0);

            if ($remaining > 0) {
                $left[$item->id] = $remaining;
            }
        }

        $split = [];

        foreach ($this->allocator->allocate($amountMinor, $left) as $itemId => $share) {
            if ($share <= 0) {
                continue;
            }

            $item = $order->items->firstWhere('id', $itemId);

            $split[$itemId] = [
                'amount' => $share,
                'allocations' => $item instanceof OrderItem ? $this->acrossCourses($item, $share) : [],
            ];
        }

        return $split;
    }

    /**
     * A bundle line's share, across the courses it was allocated to — by what
     * each course has left of its allocation. Empty for any other line: the
     * line IS the attribution.
     *
     * @return array<int, int>
     */
    private function acrossCourses(OrderItem $item, int $share): array
    {
        if ($item->allocations->isEmpty()) {
            return [];
        }

        $given = RefundLineAllocation::query()
            ->join('refund_lines', 'refund_lines.id', '=', 'refund_line_allocations.refund_line_id')
            ->join('refunds', 'refunds.id', '=', 'refund_lines.refund_id')
            ->where('refund_lines.order_item_id', $item->id)
            ->whereIn('refunds.status', [RefundStatus::Pending, RefundStatus::Completed])
            ->groupBy('refund_line_allocations.course_id')
            ->selectRaw('refund_line_allocations.course_id, SUM(refund_line_allocations.amount_minor) as given')
            ->toBase()
            ->pluck('given', 'course_id');

        $left = [];

        foreach ($item->allocations as $allocation) {
            $left[(int) $allocation->course_id] = max(0, (int) $allocation->amount_minor - (int) ($given[$allocation->course_id] ?? 0));
        }

        return array_filter($this->allocator->allocate($share, $left), static fn (int $amount): bool => $amount > 0);
    }

    /** @param  Builder<Refund>  $refund */
    private static function claiming(Builder $refund): void
    {
        $refund->whereIn('status', [RefundStatus::Pending, RefundStatus::Completed]);
    }
}
