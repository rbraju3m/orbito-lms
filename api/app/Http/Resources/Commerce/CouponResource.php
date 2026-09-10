<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Enums\CouponState;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\Product;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A coupon, for the people who manage them.
 *
 * `times_used` counts PAID orders only — the figure an academy reports on.
 * Unpaid orders still holding a use are CouponRules' business, not a number
 * anybody should read as a sale. The controller supplies the count as a
 * subquery (`paid_redemptions_count`), so a list costs one query, not one
 * per row.
 *
 * @mixin Coupon
 */
final class CouponResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $used = (int) ($this->resource->getAttribute('paid_redemptions_count') ?? 0);
        $state = CouponState::of($this->resource, $used);

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'description' => $this->description,
            'discount_type' => $this->discount_type->value,
            'percent_off' => $this->percent_off,
            'amount_off_minor' => $this->amount_off_minor,
            'currency' => $this->currency,
            'applies_to_all' => $this->applies_to_all,
            'products' => $this->whenLoaded('products', fn () => $this->products->map(fn (Product $product): array => [
                'id' => $product->uuid,
                'title' => $product->title,
                'type' => $product->purchasable_type,
            ])->values()->all()),
            'min_subtotal_minor' => $this->min_subtotal_minor,
            'max_redemptions' => $this->max_redemptions,
            'max_redemptions_per_user' => $this->max_redemptions_per_user,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'state' => $state->value,
            'state_label' => $state->label(),
            'times_used' => $used,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
