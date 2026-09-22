<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A course's, or a download's, share of a refunded BUNDLE line. Exactly one
 * of `course_id` / `download_id` is set.
 *
 * @property int $id
 * @property int $refund_line_id
 * @property int|null $course_id
 * @property int|null $download_id
 * @property int $amount_minor
 */
final class RefundLineAllocation extends Model
{
    protected $fillable = ['refund_line_id', 'course_id', 'download_id', 'amount_minor'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
