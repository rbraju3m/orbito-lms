<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A course's share of a refunded BUNDLE line.
 *
 * @property int $id
 * @property int $refund_line_id
 * @property int $course_id
 * @property int $amount_minor
 */
final class RefundLineAllocation extends Model
{
    protected $fillable = ['refund_line_id', 'course_id', 'amount_minor'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
