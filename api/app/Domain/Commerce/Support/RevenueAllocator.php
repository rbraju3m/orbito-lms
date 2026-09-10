<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Support;

/**
 * Splits one paid amount across several courses, exactly.
 *
 * A bundle sells for less than its parts, so "what did this course earn?" has
 * no answer until somebody decides one. Without this, a bundle line counts in
 * the platform total and in no course figure at all — and an instructor
 * selling mainly through bundles reads zero on their own dashboard.
 *
 * Largest remainder, weighted by list price. The whole point is the LAST
 * step: after flooring every share there is a handful of minor units left
 * over, and they are handed out one at a time to the largest fractional parts
 * so the shares sum to the total EXACTLY. Anything that rounds independently
 * loses or invents money, and the platform total then disagrees with the sum
 * of its own parts — the failure CLAUDE.md predicted for coupons, which
 * bundles reach first.
 *
 * Ties break on the array key (a course id), so the same input allocates the
 * same way every time. A split that moved a penny between two courses on
 * re-run would make the nightly rollup report drift that is not there.
 */
final class RevenueAllocator
{
    /**
     * @param  array<int, int>  $weights  course id => list price in minor units
     * @return array<int, int> course id => allocated minor units, summing to $total
     */
    public function allocate(int $total, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $sum = array_sum($weights);

        /*
         * Every part free, or priced at nothing: there is no signal about
         * relative worth, so an even split is the only defensible answer. Not
         * zero — the money exists and has to land somewhere.
         */
        if ($sum <= 0) {
            return $this->evenly($total, array_keys($weights));
        }

        $shares = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weights as $id => $weight) {
            $exact = $total * $weight;
            $share = intdiv($exact, $sum);

            $shares[$id] = $share;
            $remainders[$id] = $exact % $sum;
            $allocated += $share;
        }

        // Largest remainder first; the course id breaks ties, so the result is
        // stable across runs and a test can assert a specific split.
        uksort($remainders, static fn (int $a, int $b): int => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));

        $leftover = $total - $allocated;

        foreach (array_keys($remainders) as $id) {
            if ($leftover <= 0) {
                break;
            }

            $shares[$id]++;
            $leftover--;
        }

        return $shares;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function evenly(int $total, array $ids): array
    {
        $count = count($ids);
        $base = intdiv($total, $count);
        $leftover = $total - ($base * $count);

        // Sorted, so "who gets the odd penny" is the lowest id rather than
        // whatever order the caller happened to build the array in.
        sort($ids);

        $shares = [];

        foreach ($ids as $index => $id) {
            $shares[$id] = $base + ($index < $leftover ? 1 : 0);
        }

        return $shares;
    }
}
