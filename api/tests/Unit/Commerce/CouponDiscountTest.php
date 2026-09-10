<?php

declare(strict_types=1);

use App\Domain\Commerce\Enums\DiscountType;
use App\Domain\Commerce\Support\CouponDiscount;
use App\Domain\Commerce\Support\RevenueAllocator;

/*
 * The arithmetic under every coupon. The invariant is the one bundles already
 * live by: the shares sum to the discount EXACTLY, or the per-course revenue
 * figures and the platform total disagree by the difference.
 */

function discountSplit(DiscountType $type, int $value, array $lines): array
{
    return (new CouponDiscount(new RevenueAllocator))->split($type, $value, $lines);
}

it('rounds a percentage down once, on the total, then splits it', function (): void {
    // 10% of 1998 is 199.8 → 199. Rounding each line would give 99 + 99.
    expect(discountSplit(DiscountType::Percent, 10, [0 => 999, 1 => 999]))->toBe([0 => 100, 1 => 99]);
});

it('never takes more than a fixed coupon applies to', function (): void {
    expect(discountSplit(DiscountType::Fixed, 5_000, [7 => 1_500]))->toBe([7 => 1_500]);
});

it('weights the split by each line\'s amount', function (): void {
    // 1000 off 3000 + 1000: three quarters, one quarter.
    expect(discountSplit(DiscountType::Fixed, 1_000, [1 => 3_000, 2 => 1_000]))->toBe([1 => 750, 2 => 250]);
});

it('takes nothing from nothing', function (): void {
    expect(discountSplit(DiscountType::Percent, 50, [1 => 0]))->toBe([1 => 0]);
});

it('always splits to exactly the discount it computed', function (): void {
    mt_srand(16);

    foreach (range(1, 200) as $_) {
        $lines = [];

        foreach (range(1, mt_rand(1, 6)) as $key) {
            $lines[$key] = mt_rand(1, 250_000);
        }

        $percent = mt_rand(1, 100);
        $expected = intdiv(array_sum($lines) * $percent, 100);

        expect(array_sum(discountSplit(DiscountType::Percent, $percent, $lines)))->toBe($expected);
    }
});
