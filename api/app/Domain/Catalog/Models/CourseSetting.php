<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $course_id
 * @property bool $enable_qa
 * @property bool $enable_certificate
 * @property int|null $max_students
 */
final class CourseSetting extends Model
{
    protected $primaryKey = 'course_id';

    public $incrementing = false;

    protected $fillable = [
        'course_id', 'enable_qa', 'enable_reviews', 'enable_notes', 'enable_certificate',
        'max_students', 'enrollment_expires_days', 'drip_mode',
        'retake_allowed', 'reset_progress_allowed', 'video_completion_threshold',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enable_qa' => 'boolean',
            'enable_reviews' => 'boolean',
            'enable_notes' => 'boolean',
            'enable_certificate' => 'boolean',
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
