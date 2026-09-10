<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use App\Domain\Catalog\Models\Download;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A download exists. Commerce gives a paid one its (dormant) product, and the
 * plan counter moves — a draft counts against `max_downloads`, like a draft
 * course against `max_courses`.
 */
final class DownloadCreated
{
    use Dispatchable;

    public function __construct(public readonly Download $download) {}
}
