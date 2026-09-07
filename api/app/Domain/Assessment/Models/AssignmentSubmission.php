<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Enums\SubmissionStatus;
use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use Database\Factories\Assessment\AssignmentSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $assignment_id
 * @property int $course_item_id
 * @property int $course_id
 * @property int $user_id
 * @property int $attempt_number
 * @property SubmissionStatus $status
 * @property string|null $body
 * @property Carbon $submitted_at
 * @property bool $is_late
 * @property float|null $points_raw
 * @property float $late_penalty_points
 * @property float|null $points_earned
 * @property bool|null $passed
 * @property string|null $feedback
 * @property Carbon|null $graded_at
 */
final class AssignmentSubmission extends Model
{
    /** @use HasFactory<AssignmentSubmissionFactory> */
    use HasFactory;

    protected $fillable = [
        'assignment_id', 'course_item_id', 'course_id', 'user_id', 'enrollment_id',
        'attempt_number', 'status', 'body', 'submitted_at', 'is_late',
        'points_raw', 'late_penalty_points', 'points_earned', 'passed',
        'feedback', 'graded_by', 'graded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
            'is_late' => 'boolean',
            'passed' => 'boolean',
            'points_raw' => 'float',
            'late_penalty_points' => 'float',
            'points_earned' => 'float',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $submission): void {
            $submission->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<CourseItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CourseItem::class, 'course_item_id');
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

    /** @return BelongsTo<User, $this> */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return HasMany<AssignmentSubmissionFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(AssignmentSubmissionFile::class, 'submission_id');
    }
}
