<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Models;

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Models\CourseProgress;
use App\Domain\Progress\Models\ItemProgress;
use Carbon\CarbonInterface;
use Database\Factories\Enrollment\EnrollmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $course_id
 * @property int|null $cohort_id
 * @property int $user_id
 * @property EnrollmentStatus $status
 * @property EnrollmentSource $source
 * @property CarbonInterface $enrolled_at
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $suspended_at
 * @property string|null $suspended_reason
 * @property CarbonInterface|null $completed_at
 */
final class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id', 'cohort_id', 'user_id', 'status', 'source', 'source_id',
        'enrolled_at', 'starts_at', 'expires_at', 'completed_at',
        'suspended_at', 'suspended_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'source' => EnrollmentSource::class,
            'enrolled_at' => 'datetime',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $enrollment): void {
            $enrollment->uuid ??= (string) Str::uuid7();
            $enrollment->enrolled_at ??= now();
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

    /** @return HasOne<CourseProgress, $this> */
    public function progress(): HasOne
    {
        return $this->hasOne(CourseProgress::class);
    }

    /** @return HasMany<ItemProgress, $this> */
    public function itemProgress(): HasMany
    {
        return $this->hasMany(ItemProgress::class);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * A manual enrolment can be dated forward — a cohort that opens on the
     * 1st, a seat granted before the course goes live. Until then the row
     * exists and holds a seat, but opens nothing.
     */
    public function hasStarted(): bool
    {
        return $this->starts_at === null || ! $this->starts_at->isFuture();
    }

    /**
     * Whether this enrollment currently opens the course.
     *
     * Expiry is evaluated here rather than trusted from the status column: the
     * sweeper runs on a schedule, and access must not depend on a cron having
     * fired yet.
     */
    public function isActive(): bool
    {
        return $this->status->grantsAccess() && ! $this->hasExpired() && $this->hasStarted();
    }

    /** @param  Builder<Enrollment>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
