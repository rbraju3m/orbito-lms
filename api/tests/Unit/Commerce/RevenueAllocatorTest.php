<?php

declare(strict_types=1);

use App\Domain\Commerce\Support\RevenueAllocator;

/*
 * The only property that really matters is the last assertion in every test:
 * the parts sum to the whole. A bundle whose allocations lose a penny makes
 * the platform total and the per-course figures two different numbers for one
 * fact, which is the exact failure CLAUDE.md predicted for coupons.
 */

beforeEach(fn () => $this->allocator = new RevenueAllocator);

it('splits in proportion to list price', function (): void {
    $shares = $this->allocator->allocate(9000, [1 => 6000, 2 => 3000]);

    expect($shares)->toBe([1 => 6000, 2 => 3000])
        ->and(array_sum($shares))->toBe(9000);
});

it('discounts every course by the same proportion', function (): void {
    // £120 of courses sold for £90: everybody takes 75%.
    $shares = $this->allocator->allocate(9000, [1 => 8000, 2 => 4000]);

    expect($shares)->toBe([1 => 6000, 2 => 3000])
        ->and(array_sum($shares))->toBe(9000);
});

/* The whole reason for largest remainder. 100 / 3 does not divide. */
it('hands out the leftover minor units rather than losing them', function (): void {
    $shares = $this->allocator->allocate(100, [1 => 1, 2 => 1, 3 => 1]);

    expect(array_sum($shares))->toBe(100)
        ->and($shares)->toBe([1 => 34, 2 => 33, 3 => 33]);
});

it('breaks a tie on the course id, so the same split repeats', function (): void {
    $first = $this->allocator->allocate(1000, [7 => 300, 3 => 300, 5 => 300]);
    $again = $this->allocator->allocate(1000, [5 => 300, 7 => 300, 3 => 300]);

    // Same three courses, same money, whatever order they arrive in.
    ksort($first);
    ksort($again);

    expect($first)->toBe($again)
        ->and($first[3])->toBe(334)
        ->and(array_sum($first))->toBe(1000);
});

/* A free course in a bundle is worth nothing of the price. */
it('allocates nothing to a course with no price', function (): void {
    $shares = $this->allocator->allocate(5000, [1 => 5000, 2 => 0]);

    expect($shares)->toBe([1 => 5000, 2 => 0])
        ->and(array_sum($shares))->toBe(5000);
});

/* But if NOTHING has a price, the money still has to land somewhere. */
it('splits evenly when every weight is zero', function (): void {
    $shares = $this->allocator->allocate(1000, [4 => 0, 2 => 0, 9 => 0]);

    expect(array_sum($shares))->toBe(1000)
        ->and($shares)->toBe([2 => 334, 4 => 333, 9 => 333]);
});

it('gives a single course the whole amount', function (): void {
    expect($this->allocator->allocate(4900, [1 => 2500]))->toBe([1 => 4900]);
});

it('allocates nothing when there is nothing to allocate to', function (): void {
    expect($this->allocator->allocate(4900, []))->toBe([]);
});

/* Fuzz: whatever the inputs, the parts sum to the whole. */
it('always sums to the total', function (): void {
    for ($run = 0; $run < 200; $run++) {
        $total = random_int(1, 500_000);
        $weights = [];

        foreach (range(1, random_int(2, 8)) as $id) {
            $weights[$id] = random_int(0, 100_000);
        }

        expect(array_sum($this->allocator->allocate($total, $weights)))->toBe($total);
    }
});
