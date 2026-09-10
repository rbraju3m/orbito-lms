<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Carries the id: the row is gone by the time anyone listens.
 *
 * Added in the downloads slice to fix a bug the bundles slice shipped:
 * deleting a bundle left its product ACTIVE, so a basket still holding it
 * could check out, capture the payment, and grant nothing.
 */
final class BundleDeleted
{
    use Dispatchable;

    public function __construct(public readonly int $bundleId) {}
}
