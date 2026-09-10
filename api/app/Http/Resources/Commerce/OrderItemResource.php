<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Models\OrderItem;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One line, as it was CHARGED — the snapshot, never the live product.
 *
 * `title_snapshot` rather than `product->title` on purpose: a receipt that
 * renames itself when an author edits a course is not a receipt.
 *
 * @mixin OrderItem
 */
final class OrderItemResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'title' => $this->title_snapshot,
            'purchasable_type' => $this->purchasable_type,
            'purchasable_id' => $this->purchasable_id,
            'unit_amount_minor' => $this->unit_amount_minor,
            'discount_minor' => $this->discount_minor,
            'total_minor' => $this->total_minor,
        ];
    }
}
