<?php

declare(strict_types=1);

namespace App\Domain\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class MediaDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $ownerId,
        public readonly int $sizeBytes,
    ) {}
}
