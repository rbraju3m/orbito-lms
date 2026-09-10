<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Catalog\Models\Course;
use App\Support\Database\LivesInTenantSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one course on a bundle line was worth, in money.
 *
 * A snapshot for the same reason `order_items.title_snapshot` is: repricing a
 * course next month must not rewrite what last month's report said it earned.
 * Never recomputed, only written once at order time.
 *
 * @property int $order_item_id
 * @property int $course_id
 * @property int $amount_minor
 */
final class OrderItemAllocation extends Model
{
    use LivesInTenantSchema;

    protected $fillable = ['order_item_id', 'course_id', 'amount_minor'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
