<?php

declare(strict_types=1);

namespace App\Domain\Media\Enums;

enum MediaStatus: string
{
    /** Recorded, bytes not yet confirmed (direct-to-S3 flow). */
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
