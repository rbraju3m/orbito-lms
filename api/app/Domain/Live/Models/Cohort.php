<?php

declare(strict_types=1);

namespace App\Domain\Live\Models;

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Live\Enums\CohortStatus;
use Carbon\CarbonInterface;
use Database\Factories\Live\CohortFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A scheduled run of a course.
 *
 * NOT a copy of the course. A cohort that duplicated the curriculum would fork
 * every lesson, and an edit would have to be applied to each run separately —
 * so a cohort is a start date, a capacity and a group, and the course is still
 * the one course.
 *
 * @property int $id
 * @property string $uuid
 * @property int $course_id
 * @property string $name
 * @property CarbonInterface $starts_at
 * @property CarbonInterface|null $ends_at
 * @property string $timezone
 * @property int|null $capacity
 * @property CarbonInterface|null $enrollment_deadline
 * @property CohortStatus $status
 * @property int|null $sessions_count
 * @property int|null $enrollments_count
 */
final class Cohort extends Model
{
    /** @use HasFactory<CohortFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id', 'name', 'starts_at', 'ends_at', 'timezone',
        'capacity', 'enrollment_deadline', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'enrollment_deadline' => 'datetime',
            'capacity' => 'integer',
            'status' => CohortStatus::class,
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $cohort): void {
            $cohort->uuid ??= (string) Str::uuid7();
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

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return HasMany<LiveSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(LiveSession::class);
    }

    /**
     * Whether somebody may still join THIS run.
     *
     * Three separate questions, and the deadline is deliberately its own:
     * an academy may want a cohort listed and closed while it decides, which
     * is not the same as the deadline having passed.
     */
    public function isJoinable(): bool
    {
        if (! $this->status->isEnrollable()) {
            return false;
        }

        if ($this->enrollment_deadline !== null && $this->enrollment_deadline->isPast()) {
            return false;
        }

        return $this->placesRemaining() !== 0;
    }

    /**
     * Whether this run is part of somebody's record.
     *
     * Deleting a cohort cascades its sessions away — and their attendance with
     * them — and leaves its learners without the run they joined. One with
     * either is cancelled instead: DeleteCohort refuses it, and the resource
     * says so first, from this same method.
     *
     * Uses the loaded counts when a list already has them, so a page of runs
     * costs no extra queries. Checked on the raw attributes, not the magic
     * properties: strict mode throws on reading an attribute that was never
     * loaded, which is every cohort fetched without `withCount`.
     */
    public function isInUse(): bool
    {
        $attributes = $this->getAttributes();

        if (array_key_exists('sessions_count', $attributes) && array_key_exists('enrollments_count', $attributes)) {
            return (int) $attributes['sessions_count'] > 0 || (int) $attributes['enrollments_count'] > 0;
        }

        return $this->sessions()->exists() || $this->enrollments()->exists();
    }

    /** Null means uncapped, which is not the same as zero places left. */
    public function placesRemaining(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }

        return max(0, $this->capacity - $this->enrollments()->count());
    }

    /** @param  Builder<Cohort>  $query */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNot('status', CohortStatus::Draft);
    }
}
