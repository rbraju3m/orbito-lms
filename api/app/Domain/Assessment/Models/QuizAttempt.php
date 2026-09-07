<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Enums\AttemptResult;
use App\Domain\Assessment\Enums\AttemptStatus;
use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $quiz_id
 * @property int $user_id
 * @property int $attempt_number
 * @property AttemptStatus $status
 * @property CarbonInterface $started_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $submitted_at
 * @property string $total_points
 * @property string $earned_points
 * @property string $percent
 * @property AttemptResult|null $result
 * @property list<int>|null $question_order
 */
final class QuizAttempt extends Model
{
    protected $fillable = [
        'quiz_id', 'course_item_id', 'course_id', 'user_id', 'enrollment_id',
        'attempt_number', 'status', 'started_at', 'expires_at', 'submitted_at',
        'graded_at', 'graded_by', 'total_points', 'earned_points', 'percent',
        'result', 'question_order', 'ip', 'user_agent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'result' => AttemptResult::class,
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
            'question_order' => 'array',
            'total_points' => 'decimal:2',
            'earned_points' => 'decimal:2',
            'percent' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        self::creating(fn (self $a) => $a->uuid ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
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

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return HasMany<QuizAttemptAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(QuizAttemptAnswer::class, 'attempt_id');
    }

    /**
     * Judged against the SERVER's clock and the deadline it recorded at start.
     * A client clock is display only (ADR-06).
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function secondsRemaining(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }
}
