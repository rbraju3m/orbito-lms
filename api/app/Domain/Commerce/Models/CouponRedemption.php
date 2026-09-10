<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A coupon, on an order. Written when the order is PLACED; whether it counts
 * towards a limit is read from that order's status and age (`CouponRules`),
 * so an abandoned checkout gives its use back without anything sweeping it.
 *
 * @property int $id
 * @property int $coupon_id
 * @property int $order_id
 * @property int $user_id
 * @property int $discount_minor
 * @property string $currency
 */
final class CouponRedemption extends Model
{
    protected $fillable = ['coupon_id', 'order_id', 'user_id', 'discount_minor', 'currency'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['discount_minor' => 'integer'];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
