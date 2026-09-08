<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Models;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Enums\ReviewStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Engagement\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One learner's verdict on one course.
 *
 * @property int $id
 * @property string $uuid
 * @property int $course_id
 * @property int $user_id
 * @property int|null $enrollment_id
 * @property int $rating
 * @property string|null $title
 * @property string|null $body
 * @property ReviewStatus $status
 * @property string|null $instructor_reply
 * @property CarbonInterface|null $replied_at
 * @property CarbonInterface|null $published_at
 *
 * `user_id` crosses the schema boundary to a table with no foreign key (§ Multi-tenancy),
 * so the row it names can be gone.
 * @property-read User|null $user
 * @property-read Enrollment|null $enrollment
 */
final class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id', 'user_id', 'enrollment_id', 'rating', 'title', 'body',
        'status', 'instructor_reply', 'replied_at', 'published_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'status' => ReviewStatus::class,
            'replied_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $review): void {
            $review->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
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

    /**
     * The rows that count toward a course's rating.
     *
     * One definition, used by the aggregate maintenance AND the nightly
     * reconciliation, so the two cannot compute different numbers.
     *
     * @param  Builder<Review>  $query
     */
    public function scopeCounted(Builder $query): void
    {
        $query->where('status', ReviewStatus::Published);
    }

    public function hasReply(): bool
    {
        return $this->instructor_reply !== null && $this->instructor_reply !== '';
    }
}
