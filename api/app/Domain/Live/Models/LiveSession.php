<?php

declare(strict_types=1);

namespace App\Domain\Live\Models;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Enums\LiveProvider;
use App\Domain\Live\Enums\SessionStatus;
use App\Domain\Media\Models\Media;
use Carbon\CarbonInterface;
use Database\Factories\Live\LiveSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

/**
 * One scheduled meeting.
 *
 * `starts_at` is UTC and `timezone` is the IANA zone it was SCHEDULED in.
 * Both are needed: the instant is what the reminder sweeper and the calendar
 * sort on, and the zone is what "Tuesdays at 7pm Dhaka time" means when
 * daylight saving moves somewhere else in the world.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $course_id
 * @property int|null $cohort_id
 * @property LiveProvider $provider
 * @property string|null $external_id
 * @property string|null $join_url
 * @property string|null $host_url
 * @property int $host_id
 * @property string $title
 * @property string|null $description
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property string $timezone
 * @property SessionStatus $status
 * @property int|null $recording_media_id
 * @property CarbonInterface|null $reminder_sent_at
 */
final class LiveSession extends Model
{
    /** @use HasFactory<LiveSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id', 'cohort_id', 'provider', 'external_id', 'join_url', 'host_url',
        'host_id', 'title', 'description', 'starts_at', 'ends_at', 'timezone',
        'status', 'recording_media_id', 'reminder_sent_at',
    ];

    /** A host link opens the meeting AS the host. It never leaves the server. */
    protected $hidden = ['host_url'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => LiveProvider::class,
            'status' => SessionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $session): void {
            $session->uuid ??= (string) Str::uuid7();
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

    /** @return BelongsTo<Cohort, $this> */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /** @return BelongsTo<User, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function recording(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'recording_media_id');
    }

    /** @return HasMany<SessionAttendance, $this> */
    public function attendance(): HasMany
    {
        return $this->hasMany(SessionAttendance::class);
    }

    /**
     * The curriculum item this session sits on, when it sits on one.
     *
     * A MorphOne like every other itemable — `course_items` points here, not
     * the other way round, so there is one link and it cannot disagree with
     * itself.
     *
     * @return MorphOne<CourseItem, $this>
     */
    public function item(): MorphOne
    {
        return $this->morphOne(CourseItem::class, 'itemable');
    }

    /**
     * Where this session is RIGHT NOW.
     *
     * Derived from the clock, never swept — the same reasoning as drip, sale
     * prices and scheduled announcements. A status that needed a cron to
     * become true would be wrong for as long as the cron was late, which is
     * exactly when somebody is trying to join.
     */
    public function currentStatus(): SessionStatus
    {
        if ($this->status === SessionStatus::Cancelled) {
            return SessionStatus::Cancelled;
        }

        $now = now();

        /*
         * The join window opens fifteen minutes early. People arrive early for
         * a class, and a link that refuses them until the second is a support
         * ticket every single time.
         */
        if ($now->greaterThanOrEqualTo($this->starts_at->copy()->subMinutes(15))
            && $now->lessThan($this->ends_at)) {
            return SessionStatus::Live;
        }

        return $now->greaterThanOrEqualTo($this->ends_at)
            ? SessionStatus::Ended
            : SessionStatus::Scheduled;
    }

    public function isJoinable(): bool
    {
        return $this->currentStatus()->isJoinable();
    }

    /** @param  Builder<LiveSession>  $query */
    public function scopeUpcoming(Builder $query): void
    {
        $query->whereNot('status', SessionStatus::Cancelled)
            ->where('ends_at', '>=', now());
    }
}
