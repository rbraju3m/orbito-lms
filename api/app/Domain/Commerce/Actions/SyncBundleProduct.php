<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Catalog\Models\Bundle;
use App\Domain\Commerce\Enums\ProductStatus;
use App\Domain\Commerce\Models\Product;

/**
 * Keeps a bundle's sellable twin in step with the bundle. The twin of
 * `SyncCourseProduct`, and idempotent for the same reason.
 *
 * Unlike a course, a bundle's product is created while it is still a DRAFT.
 * The publish checklist requires a price, a price hangs off a product, and a
 * product that only appeared at publication would make the checklist
 * permanently unsatisfiable.
 */
final class SyncBundleProduct
{
    public function handle(Bundle $bundle): Product
    {
        $product = Product::query()->firstOrNew([
            'purchasable_type' => $bundle->getMorphClass(),
            'purchasable_id' => $bundle->id,
        ]);

        $product->fill([
            'title' => $bundle->title,
            /*
             * Sellable only while the bundle is published. An archived bundle
             * that stayed purchasable would take money for a shelf nobody
             * maintains — and the grant would succeed, which is worse than
             * failing.
             */
            'status' => $bundle->status->isLive() ? ProductStatus::Active : ProductStatus::Inactive,
        ])->save();

        return $product;
    }
}
