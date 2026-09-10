<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Support;

use App\Domain\Commerce\Enums\DiscountType;

/**
 * How much a coupon takes off, and from which lines — arithmetic only.
 *
 * The discount is computed once, on what it applies to, and then SPLIT across
 * those lines by largest remainder (`RevenueAllocator`), weighted by each
 * line's amount. The shares sum to the discount exactly. That is the whole
 * point: every revenue figure in the product sums order LINES, and the moment
 * a line total stops being net of its share, the per-course figures and the
 * platform total disagree by the discount (docs/FEATURE_MATRIX.md J9).
 *
 * Rounding is down, in the academy's favour, and happens once — on the total,
 * never per line. Two lines at 10% of 999 each would otherwise lose a minor
 * unit each to rounding that the single figure does not.
 */
final class CouponDiscount
{
    public function __construct(private readonly RevenueAllocator $allocator) {}

    /**
     * @param  array<int, int>  $eligible  line key => amount in minor units, for the lines the coupon applies to
     * @return array<int, int> line key => discount, summing to the coupon's total discount
     */
    public function split(DiscountType $type, int $value, array $eligible): array
    {
        $subtotal = array_sum($eligible);

        if ($subtotal <= 0) {
            return array_map(static fn (): int => 0, $eligible);
        }

        $total = match ($type) {
            DiscountType::Percent => intdiv($subtotal * min(max($value, 0), 100), 100),
            // Never more than what it applies to: a £20 coupon on a £15
            // basket makes it free, not a £5 credit.
            DiscountType::Fixed => min(max($value, 0), $subtotal),
        };

        return $this->allocator->allocate($total, $eligible);
    }
}
