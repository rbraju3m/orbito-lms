<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use App\Domain\Catalog\Models\DownloadGrant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody came to own a download. Fired once per grant — never for the
 * second click that found the grant already there — so anything counting
 * owners counts people, not clicks.
 */
final class DownloadGranted
{
    use Dispatchable;

    public function __construct(public readonly DownloadGrant $grant) {}
}
