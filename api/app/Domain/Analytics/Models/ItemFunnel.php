<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Curriculum\Models\CourseItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a course loses people.
 *
 * One row per item, replaced wholesale on every run — a funnel is a question
 * about the present state of everybody enrolled, not about a day.
 *
 * @property int $course_item_id
 * @property int $course_id
 * @property int $started
 * @property int $completed
 * @property int|null $avg_seconds
 * @property string $drop_off_rate
 * @property CarbonInterface $computed_at
 */
final class ItemFunnel extends Model
{
    protected $table = 'analytics_item_funnel';

    public $incrementing = false;

    protected $primaryKey = 'course_item_id';

    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started' => 'integer',
            'completed' => 'integer',
            'avg_seconds' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CourseItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CourseItem::class, 'course_item_id');
    }
}
