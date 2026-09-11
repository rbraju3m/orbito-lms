<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One order line's share of a refund. For a bundle line, its `allocations`
 * say which of the bundle's courses gave it back — the mirror image of
 * `order_item_allocations`, so per-course revenue stays honest after a refund
 * exactly as it was after the sale.
 *
 * @property int $id
 * @property int $refund_id
 * @property int $order_item_id
 * @property int $amount_minor
 */
final class RefundLine extends Model
{
    protected $fillable = ['refund_id', 'order_item_id', 'amount_minor'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return HasMany<RefundLineAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(RefundLineAllocation::class);
    }
}
