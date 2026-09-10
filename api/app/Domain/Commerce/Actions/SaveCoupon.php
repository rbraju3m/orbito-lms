<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\DiscountType;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a coupon, or replaces an existing one's definition.
 *
 * Replacing, not patching: the admin form always sends the whole coupon, and
 * a partial update is how a coupon ends up "fixed" with no amount. Editing a
 * coupon never rewrites an order it was used on — the order froze its code
 * and every discount when it was placed.
 */
final class SaveCoupon
{
    /**
     * @param  array{code: string, description: string|null, discount_type: DiscountType, percent_off: int|null,
     *     amount_off_minor: int|null, currency: string|null, applies_to_all: bool, min_subtotal_minor: int|null,
     *     max_redemptions: int|null, max_redemptions_per_user: int|null, starts_at: string|null,
     *     ends_at: string|null, is_active: bool}  $data
     * @param  list<int>  $productIds  ignored when the coupon applies to everything
     */
    public function handle(User $actor, array $data, array $productIds, ?Coupon $coupon = null): Coupon
    {
        // Only the value that matters for the type is kept, so a coupon never
        // carries a stale percentage beside the amount it actually uses.
        $data['percent_off'] = $data['discount_type'] === DiscountType::Percent ? $data['percent_off'] : null;
        $data['amount_off_minor'] = $data['discount_type'] === DiscountType::Fixed ? $data['amount_off_minor'] : null;

        return DB::transaction(function () use ($actor, $data, $productIds, $coupon): Coupon {
            $coupon ??= new Coupon(['created_by' => $actor->id]);
            $coupon->fill($data)->save();

            $coupon->products()->sync($data['applies_to_all'] ? [] : $productIds);

            return $coupon->refresh();
        });
    }
}
