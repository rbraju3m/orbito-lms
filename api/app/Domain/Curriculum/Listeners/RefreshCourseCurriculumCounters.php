<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Listeners;

use App\Domain\Curriculum\Events\CurriculumChanged;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;

/**
 * Keeps `courses.section_count`, `item_count` and `total_duration_seconds`
 * true.
 *
 * These are read on every course card, so they are maintained on write rather
 * than counted on read (docs/ARCHITECTURE §7).
 */
final class RefreshCourseCurriculumCounters
{
    public function handle(CurriculumChanged $event): void
    {
        $course = $event->course;

        $items = CourseItem::query()->where('course_id', $course->id)->published();

        $course->forceFill([
            'section_count' => CourseSection::where('course_id', $course->id)->count(),
            'item_count' => (clone $items)->count(),
            'total_duration_seconds' => (int) (clone $items)->sum('duration_seconds'),
        ])->saveQuietly();
    }
}
