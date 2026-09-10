<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A course became paid, or became free.
 *
 * Catalog owns `pricing_model`; Commerce owns whether there is something to
 * sell. This is how the second finds out, rather than Catalog reaching into
 * it — the same dependency rule the usage counters follow.
 *
 * Fired only on an actual change, not on every save. An event that fires when
 * nothing happened is one every listener has to re-derive the truth from
 * (§ Phase 16, `EnrollmentAccessChanged`).
 */
final class CoursePricingChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Course $course,
        public readonly PricingModel $from,
        public readonly PricingModel $to,
    ) {}
}
