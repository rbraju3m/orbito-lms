<?php

declare(strict_types=1);

namespace App\Domain\Progress\Models;

use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Progress\Enums\ItemProgressStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $enrollment_id
 * @property int $course_item_id
 * @property ItemProgressStatus $status
 * @property int $watch_position_seconds
 * @property int $watch_max_seconds
 * @property CarbonInterface|null $completed_at
 */
final class ItemProgress extends Model
{
    protected $table = 'item_progress';

    protected $fillable = [
        'enrollment_id', 'course_item_id', 'course_id', 'user_id',
        'status', 'first_seen_at', 'completed_at',
        'watch_position_seconds', 'watch_max_seconds', 'view_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ItemProgressStatus::class,
            'first_seen_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<CourseItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CourseItem::class, 'course_item_id');
    }
}
