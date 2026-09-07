<?php

declare(strict_types=1);

namespace App\Domain\Progress\Queries;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Data\DripState;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Curriculum\Support\DripSchedule;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Progress\Enums\ItemProgressStatus;
use App\Domain\Progress\Models\ItemProgress;
use Illuminate\Support\Collection;

/**
 * The player bootstrap: the whole learner-facing curriculum with this
 * enrollment's progress and drip state attached, in a bounded number of
 * queries regardless of course size.
 */
final class PlayerQuery
{
    public function __construct(private readonly DripSchedule $drip) {}

    /**
     * @return Collection<int, CourseSection>
     */
    public function curriculumWithProgress(
        Course $course,
        ?Enrollment $enrollment,
        bool $isStaff = false,
    ): Collection {
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

        /** @var Collection<int, CourseItem> $items */
        $items = $sections->flatMap(fn (CourseSection $section) => $section->items);

        // Drip for the whole tree in one pass, reusing the progress map above.
        // Per-item evaluation here would be a query per lesson — the exact
        // shape ADR-02 exists to avoid.
        $drip = $isStaff
            ? collect()
            : $this->drip->evaluate($course, $items, $enrollment, $progress);

        foreach ($sections as $section) {
            foreach ($section->items as $item) {
                $row = $progress->get($item->id);
                $state = $drip->get($item->id) ?? DripState::open();

                $item->setAttribute('progress_status', $row->status ?? ItemProgressStatus::NotStarted);
                $item->setAttribute('watch_position_seconds', $row->watch_position_seconds ?? 0);
                $item->setAttribute('drip_state', $state);
            }
        }

        return $sections;
    }
}
