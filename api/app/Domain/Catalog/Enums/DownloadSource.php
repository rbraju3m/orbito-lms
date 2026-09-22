<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** How somebody came to own a download. */
enum DownloadSource: string
{
    case Purchase = 'purchase';
    /**
     * Inside a bundle that was bought — the twin of `EnrollmentSource::Bundle`.
     * `order_id` is the bundle's order, so a refund of it revokes exactly this.
     */
    case Bundle = 'bundle';
    case Free = 'free';
    /** Staff granting it — a gift, a support fix, a replacement. */
    case Manual = 'manual';
}
