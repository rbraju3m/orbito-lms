<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Events;

use App\Domain\Gamification\Models\PointTransaction;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A learner's balance moved.
 *
 * Fired only when a row was actually written — a rule refused by its dedupe
 * key, its cooldown or its daily cap fires nothing, because nothing happened.
 * Badge evaluation listens to this rather than to the domain events, so it
 * runs exactly as often as the balance changes.
 */
final class PointsAwarded
{
    use Dispatchable;

    public function __construct(
        public readonly PointTransaction $transaction,
        public readonly int $balance,
    ) {}
}
