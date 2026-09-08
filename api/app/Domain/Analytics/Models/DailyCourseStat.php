<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Catalog\Models\Course;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One course, one UTC day.
 *
 * Derived and rebuildable — drop this table and `analytics:rollup --days=90`
 * puts it back. Nothing here is a fact anybody would lose.
 *
 * @property CarbonInterface $date
 * @property int $course_id
 * @property int $views
 * @property int $enrollments
 * @property int $completions
 * @property int $revenue_minor
 * @property string $currency
 * @property int $active_learners
 */
final class DailyCourseStat extends Model
{
    protected $table = 'analytics_daily_course';

    // Composite primary key: nothing addresses one of these rows by id.
    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'views' => 'integer',
            'enrollments' => 'integer',
            'completions' => 'integer',
            'revenue_minor' => 'integer',
            'active_learners' => 'integer',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
