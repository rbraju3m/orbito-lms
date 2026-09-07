<?php

declare(strict_types=1);

namespace App\Domain\Media\Enums;

enum MediaDisk: string
{
    /** Avatars, thumbnails, category art — safe to serve from a CDN. */
    case Public = 'public';
    /** Everything a learner must have paid or enrolled for (ADR-09). */
    case Private = 'private';

    public function isPrivate(): bool
    {
        return $this === self::Private;
    }
}
