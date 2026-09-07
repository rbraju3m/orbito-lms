<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A snapshot, not a reference.
 *
 * Title and price are copied at order time so editing — or deleting — the
 * product later cannot rewrite what somebody was charged. `product_id` is
 * nullable for exactly that reason.
 *
 * @property string $purchasable_type
 * @property int $purchasable_id
 * @property int $unit_amount_minor
 * @property int $total_minor
 */
final class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'purchasable_type', 'purchasable_id',
        'title_snapshot', 'unit_amount_minor', 'total_minor',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_amount_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return MorphTo<Model, $this> */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }
}
