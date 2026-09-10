<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\BundleDeleted;
use App\Domain\Catalog\Models\Bundle;

/**
 * Deleting a bundle takes nothing from anyone who bought it — the enrolments
 * it granted are enrolments like any other, and each order line keeps its own
 * title snapshot. What it MUST do is stop the bundle being sold, which is what
 * `BundleDeleted` tells Commerce.
 */
final class DeleteBundle
{
    public function handle(Bundle $bundle): void
    {
        $id = $bundle->id;
        $bundle->delete();

        BundleDeleted::dispatch($id);
    }
}
