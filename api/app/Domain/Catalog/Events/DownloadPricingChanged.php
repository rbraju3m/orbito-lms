<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use App\Domain\Catalog\Enums\DownloadPricing;
use App\Domain\Catalog\Models\Download;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Free ⇄ paid. Fired only on a real change — the twin of
 * `CoursePricingChanged`, and the wire whose absence for courses made every
 * paid course unpublishable for six phases.
 */
final class DownloadPricingChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Download $download,
        public readonly DownloadPricing $from,
        public readonly DownloadPricing $to,
    ) {}
}
