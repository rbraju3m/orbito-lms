<?php

declare(strict_types=1);

namespace App\Domain\Progress\Models;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The stored aggregate (ADR-02). Never computed on read.
 *
 * @property int $enrollment_id
 * @property int $course_id
 * @property int $user_id
 * @property int $completed_items
 * @property int $total_items
 * @property string $percent
 * @property int|null $last_item_id
 * @property CarbonInterface|null $last_activity_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 */
final class CourseProgress extends Model
{
    protected $table = 'course_progress';

    protected $primaryKey = 'enrollment_id';

    public $incrementing = false;

    protected $fillable = [
        'enrollment_id', 'course_id', 'user_id',
        'completed_items', 'total_items', 'percent',
        'last_item_id', 'last_activity_at', 'started_at', 'completed_at', 'total_watch_seconds',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'percent' => 'decimal:2',
            'last_activity_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CourseItem, $this> */
    public function lastItem(): BelongsTo
    {
        return $this->belongsTo(CourseItem::class, 'last_item_id');
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
