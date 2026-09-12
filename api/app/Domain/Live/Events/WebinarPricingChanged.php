<?php

declare(strict_types=1);

namespace App\Domain\Live\Events;

use App\Domain\Live\Models\Webinar;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Free ⇄ paid, and fired only on a REAL flip.
 *
 * The twin of `CoursePricingChanged` and `DownloadPricingChanged`. Saving the
 * form again with the switch untouched changes nothing and announces nothing:
 * an operation is not a transition (§ Patterns established in Phase 16).
 */
final class WebinarPricingChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Webinar $webinar,
        public readonly bool $wasPaid,
        public readonly bool $isPaid,
    ) {}
}
