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

    public function label(): string
    {
        return match ($this) {
            self::CoursesTotal => 'Courses',
            self::CoursesPublished => 'Published courses',
            self::StorageBytes => 'Storage used',
            self::MediaFiles => 'Media files',
            self::Students => 'Students',
            self::Instructors => 'Instructors',
        };
    }
}
