<?php

declare(strict_types=1);

namespace App\Domain\Progress\Queries;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Progress\Enums\ItemProgressStatus;
use App\Domain\Progress\Models\ItemProgress;
use Illuminate\Support\Collection;

/**
 * The player bootstrap: the whole learner-facing curriculum with this
 * enrollment's progress attached, in a bounded number of queries regardless of
 * course size.
 */
final class PlayerQuery
{
    /** @return Collection<int, CourseSection> */
    public function curriculumWithProgress(Course $course, ?Enrollment $enrollment): Collection
    {
        $sections = CourseSection::query()
            ->where('course_id', $course->id)
            ->with(['items' => fn ($q) => $q->published()->orderBy('position')])
            ->orderBy('position')
            ->get()
            ->filter(fn (CourseSection $section) => $section->items->isNotEmpty())
            ->values();

        // One query for every item's progress, keyed for O(1) attachment.
        // Course staff and anonymous visitors have no enrollment, so there is
        // nothing to look up — but the attributes are still set, because the
        // response shape must not change with the caller (docs/API.md §1).
        $progress = $enrollment === null
            ? collect()
            : ItemProgress::query()
                ->where('enrollment_id', $enrollment->id)
                ->get()
                ->keyBy('course_item_id');

        foreach ($sections as $section) {
            foreach ($section->items as $item) {
                $row = $progress->get($item->id);

                $item->setAttribute('progress_status', $row->status ?? ItemProgressStatus::NotStarted);
                $item->setAttribute('watch_position_seconds', $row->watch_position_seconds ?? 0);
            }
        }

        return $sections;
    }
}
