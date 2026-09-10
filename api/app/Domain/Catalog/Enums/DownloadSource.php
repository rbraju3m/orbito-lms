<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** How somebody came to own a download. */
enum DownloadSource: string
{
    case Purchase = 'purchase';
    case Free = 'free';
    /** Staff granting it — a gift, a support fix, a replacement. */
    case Manual = 'manual';
}
