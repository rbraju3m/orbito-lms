<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Catalog\Models\Download;
use App\Domain\Commerce\Enums\ProductStatus;
use App\Domain\Commerce\Models\Product;

/**
 * Keeps a download's sellable twin in step with it — the third of these,
 * after `SyncCourseProduct` and `SyncBundleProduct`, and idempotent for the
 * same reason. A free download has no product, exactly like a free course.
 */
final class SyncDownloadProduct
{
    public function handle(Download $download): ?Product
    {
        if ($download->pricing_model->isFree()) {
            Product::query()
                ->where('purchasable_type', $download->getMorphClass())
                ->where('purchasable_id', $download->id)
                ->update(['status' => ProductStatus::Inactive]);

            return null;
        }

        $product = Product::query()->firstOrNew([
            'purchasable_type' => $download->getMorphClass(),
            'purchasable_id' => $download->id,
        ]);

        $product->fill([
            'title' => $download->title,
            // Created while still a draft so it can be priced; sellable only
            // once published.
            'status' => $download->status->isLive() ? ProductStatus::Active : ProductStatus::Inactive,
        ])->save();

        return $product;
    }
}
