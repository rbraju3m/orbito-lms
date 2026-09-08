<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One instructor, one UTC day.
 *
 * `rating_avg` is a SNAPSHOT of the average across their courses on that day,
 * not a recomputation. A trend line has to survive a course being deleted, and
 * re-deriving it later would silently rewrite history every time one was.
 *
 * @property CarbonInterface $date
 * @property int $instructor_id
 * @property int $enrollments
 * @property int $revenue_minor
 * @property string $currency
 * @property string $rating_avg
 */
final class DailyInstructorStat extends Model
{
    protected $table = 'analytics_daily_instructor';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'enrollments' => 'integer',
            'revenue_minor' => 'integer',
        ];
    }
}
