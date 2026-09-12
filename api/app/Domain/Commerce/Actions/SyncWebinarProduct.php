<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\ProductStatus;
use App\Domain\Commerce\Models\Product;
use App\Domain\Live\Models\Webinar;

/**
 * Keeps a webinar's sellable twin in step with it — the fourth of these, after
 * courses, bundles and downloads, and idempotent for the same reason.
 *
 * A free webinar has no product, exactly like a free course: the row is
 * retired rather than deleted, because an order line placed while it was paid
 * still points at it, and the PRICE on it survives so flipping back to paid
 * does not lose the figure somebody typed.
 *
 * The product exists while the webinar is still a DRAFT — the publish rule
 * wants a price and a price hangs off a product, so a product that only
 * appeared at publication would make that rule unsatisfiable. It is sellable
 * only once the webinar is published: a cancelled event that could still be
 * bought would take money for a room nobody is opening.
 */
final class SyncWebinarProduct
{
    public function handle(Webinar $webinar): ?Product
    {
        if (! $webinar->is_paid) {
            Product::query()
                ->where('purchasable_type', $webinar->getMorphClass())
                ->where('purchasable_id', $webinar->id)
                ->update(['status' => ProductStatus::Inactive]);

            return null;
        }

        $product = Product::query()->firstOrNew([
            'purchasable_type' => $webinar->getMorphClass(),
            'purchasable_id' => $webinar->id,
        ]);

        $product->fill([
            'title' => $webinar->title,
            'status' => $webinar->status->isOpen() ? ProductStatus::Active : ProductStatus::Inactive,
        ])->save();

        return $product;
    }
}
