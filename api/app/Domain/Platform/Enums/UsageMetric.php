<?php

declare(strict_types=1);

namespace App\Domain\Platform\Enums;

/**
 * The dimensions plan limits are sold on (see docs/KLASIO_REFERENCE.md §3).
 *
 * Billing lands in Phase 16, but these counters must be maintained from the
 * moment the rows they count start existing — "how many students did this
 * instructor have last year" cannot be backfilled.
 */
enum UsageMetric: string
{
    case CoursesTotal = 'courses_total';
    case CoursesPublished = 'courses_published';
    case StorageBytes = 'storage_bytes';
    case MediaFiles = 'media_files';
    case Students = 'students';
    case Instructors = 'instructors';
    case Downloads = 'downloads';

    public function label(): string
    {
        return match ($this) {
            self::CoursesTotal => 'Courses',
            self::CoursesPublished => 'Published courses',
            self::StorageBytes => 'Storage used',
            self::MediaFiles => 'Media files',
            self::Students => 'Students',
            self::Instructors => 'Instructors',
            self::Downloads => 'Digital downloads',
        };
    }

    /**
     * The key this metric is capped under inside `plans.limits`, or null when
     * the metric is measured but never sold.
     *
     * The two vocabularies are deliberately separate. A counter is named for
     * what it counts; a plan key is a PRICE LIST entry an operator types into
     * a JSON column, and renaming a counter must not silently uncap every
     * academy on the platform. This map is the only place they meet.
     */
    public function planKey(): ?string
    {
        return match ($this) {
            self::CoursesTotal => 'max_courses',
            self::Students => 'max_students',
            self::Instructors => 'max_instructors',
            self::StorageBytes => 'max_storage_bytes',
            self::MediaFiles => 'max_media_files',
            // Klasio meters this: 25 → unlimited (KLASIO_REFERENCE §3).
            self::Downloads => 'max_downloads',
            // Publishing is a lifecycle state, not an allowance. Capping it
            // would mean unpublishing somebody's live course to make room.
            self::CoursesPublished => null,
        };
    }

    /**
     * Whether exceeding the cap BLOCKS the write, or is merely reported.
     *
     * Courses and instructor seats are the academy's own decisions, so the
     * academy is the right party to stop. Students are not: a learner enrols
     * — often having just paid — and cannot do anything about their academy's
     * plan. Turning them away at the door punishes the wrong person, so the
     * student cap is counted, surfaced as over-limit, and never enforced.
     * Storage the same, until a plan actually declares a byte cap.
     */
    public function isEnforced(): bool
    {
        return match ($this) {
            // A download is stocked by the academy, so the academy is the
            // right party to stop — the same argument as courses.
            self::CoursesTotal, self::Instructors, self::Downloads => true,
            default => false,
        };
    }

    /** Bytes render as "2.4 GB"; everything else is a plain count. */
    public function isBytes(): bool
    {
        return $this === self::StorageBytes;
    }

    /**
     * The metrics an academy is shown, in the order it is shown them.
     *
     * @return list<self>
     */
    public static function billable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $metric): bool => $metric->planKey() !== null,
        ));
    }
}
