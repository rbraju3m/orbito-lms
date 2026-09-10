<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Carries the id, not the model: the row is gone by the time anyone listens. */
final class DownloadDeleted
{
    use Dispatchable;

    public function __construct(public readonly int $downloadId) {}
}
