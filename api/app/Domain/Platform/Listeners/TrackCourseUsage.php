<?php

declare(strict_types=1);

namespace App\Domain\Platform\Listeners;

use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Events\CourseCreated;
use App\Domain\Catalog\Events\CourseDeleted;
use App\Domain\Catalog\Events\CourseStatusChanged;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Support\UsageCounters;

/**
 * Keeps course counts current per owner and platform-wide.
 *
 * Catalog fires events; Platform listens. Catalog knows nothing about billing
 * metrics, which is the dependency rule working as intended.
 */
final class TrackCourseUsage
{
    public function __construct(private readonly UsageCounters $counters) {}

    public function created(CourseCreated $event): void
    {
        $owner = $event->course->owner;

        $this->counters->increment(UsageMetric::CoursesTotal, $owner);
        $this->counters->increment(UsageMetric::CoursesTotal);
    }

    public function statusChanged(CourseStatusChanged $event): void
    {
        $owner = $event->course->owner;

        if ($event->became(CourseStatus::Published)) {
            $this->counters->increment(UsageMetric::CoursesPublished, $owner);
            $this->counters->increment(UsageMetric::CoursesPublished);
        }

        if ($event->left(CourseStatus::Published)) {
            $this->counters->decrement(UsageMetric::CoursesPublished, $owner);
            $this->counters->decrement(UsageMetric::CoursesPublished);
        }
    }

    public function deleted(CourseDeleted $event): void
    {
        $owner = User::find($event->ownerId);

        $this->counters->decrement(UsageMetric::CoursesTotal, $owner);
        $this->counters->decrement(UsageMetric::CoursesTotal);

        if ($event->wasPublished) {
            $this->counters->decrement(UsageMetric::CoursesPublished, $owner);
            $this->counters->decrement(UsageMetric::CoursesPublished);
        }
    }
}
