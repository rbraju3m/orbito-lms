<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Curriculum\Enums\DripMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $course_id
 * @property bool $enable_qa
 * @property bool $moderate_reviews
 * @property bool $enable_certificate
 * @property int|null $max_students
 * @property int|null $enrollment_expires_days
 * @property DripMode $drip_mode
 * @property bool $retake_allowed
 * @property bool $reset_progress_allowed
 * @property int $video_completion_threshold
 */
final class CourseSetting extends Model
{
    protected $primaryKey = 'course_id';

    public $incrementing = false;

    protected $fillable = [
        'course_id', 'enable_qa', 'enable_reviews', 'moderate_reviews', 'enable_notes', 'enable_certificate',
        'max_students', 'enrollment_expires_days', 'drip_mode',
        'retake_allowed', 'reset_progress_allowed', 'video_completion_threshold',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enable_qa' => 'boolean',
            'moderate_reviews' => 'boolean',
            'enable_reviews' => 'boolean',
            'enable_notes' => 'boolean',
            'enable_certificate' => 'boolean',
            'drip_mode' => DripMode::class,
            'retake_allowed' => 'boolean',
            'reset_progress_allowed' => 'boolean',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
