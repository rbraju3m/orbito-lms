<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Support;

use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseSetting;
use App\Domain\Curriculum\Data\DripState;
use App\Domain\Curriculum\Enums\DripMode;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Progress\Enums\ItemProgressStatus;
use App\Domain\Progress\Models\ItemProgress;
use Illuminate\Support\Collection;

/**
 * When a course releases each item to a given learner.
 *
 * This is NOT a second access check. `CourseAccess` remains the only answer to
 * "may they consume this?" (ADR-03); it calls this class for the drip half of
 * that answer, and nothing else may consult it to gate content.
 *
 * Two entry points on purpose. The player asks about a hundred items at once
 * and must not pay a query per item, so `evaluate()` takes the whole
 * collection plus the progress map the caller already loaded. `forItem()` is
 * the single-item path and loads what little it needs itself.
 */
final class DripSchedule
{
    /**
     * @param  Collection<int, CourseItem>  $items  in course-global position order
     * @param  Collection<int, ItemProgress>  $itemProgress  keyed by course_item_id
     * @return Collection<int, DripState> keyed by course_item_id
     */
    public function evaluate(
        Course $course,
        Collection $items,
        ?Enrollment $enrollment,
        Collection $itemProgress,
    ): Collection {
        $mode = $this->modeFor($course);

        if (! $mode->isActive() || $enrollment === null) {
            return $items->mapWithKeys(fn (CourseItem $item) => [$item->id => DripState::open()]);
        }

        $ordered = $items->sortBy('position')->values();
        $completedIds = $itemProgress
            ->filter(fn (ItemProgress $row) => $row->status === ItemProgressStatus::Completed)
            ->keys()
            ->all();

        // Sequential asks "was the item before this one finished?", so it
        // needs the predecessor map once rather than a query per item.
        $predecessors = $mode === DripMode::Sequential
            ? $this->predecessorMap($ordered)
            : collect();

        return $ordered->mapWithKeys(function (CourseItem $item) use (
            $mode, $enrollment, $completedIds, $predecessors
        ): array {
            $blocker = $predecessors->get($item->id);

            return [$item->id => $this->state(
                $item,
                $mode,
                $enrollment,
                $blocker instanceof CourseItem ? $blocker : null,
                in_array($blocker?->id, $completedIds, true),
            )];
        });
    }

    /**
     * One item, for the item endpoint and CourseAccess::forItem().
     */
    public function forItem(CourseItem $item, ?Enrollment $enrollment): DripState
    {
        $item->loadMissing('course.setting');
        $mode = $this->modeFor($item->course);

        if (! $mode->isActive() || $enrollment === null) {
            return DripState::open();
        }

        if ($mode !== DripMode::Sequential) {
            return $this->state($item, $mode, $enrollment, null, false);
        }

        $blocker = $this->blockerFor($item);

        $blockerCompleted = $blocker !== null && ItemProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('course_item_id', $blocker->id)
            ->where('status', ItemProgressStatus::Completed)
            ->exists();

        return $this->state($item, $mode, $enrollment, $blocker, $blockerCompleted);
    }

    private function state(
        CourseItem $item,
        DripMode $mode,
        Enrollment $enrollment,
        ?CourseItem $blocker,
        bool $blockerCompleted,
    ): DripState {
        // Preview items are the marketing surface. Dripping them would hide
        // the very thing that sells the course.
        if ($item->is_preview) {
            return DripState::open();
        }

        return match ($mode) {
            DripMode::None => DripState::open(),

            DripMode::ByDate => $item->drip_available_at === null
                || ! $item->drip_available_at->isFuture()
                    ? DripState::open()
                    : DripState::until($item->drip_available_at),

            DripMode::ByDays => $this->byDays($item, $enrollment),

            DripMode::Sequential => $blocker === null || $blockerCompleted
                ? DripState::open()
                : DripState::behind($blocker->id, $blocker->title),
        };
    }

    private function byDays(CourseItem $item, Enrollment $enrollment): DripState
    {
        if ($item->drip_after_days === null || $item->drip_after_days <= 0) {
            return DripState::open();
        }

        // Dated from when access actually began, not when the row was written:
        // a seat granted in advance must not burn its first week unopened.
        $from = $enrollment->starts_at ?? $enrollment->enrolled_at;
        $unlocksAt = $from->copy()->addDays($item->drip_after_days);

        return $unlocksAt->isFuture() ? DripState::until($unlocksAt) : DripState::open();
    }

    /**
     * The item that must be finished first: an explicit `drip_after_item_id`
     * when the author set one, otherwise the preceding completable item.
     */
    private function blockerFor(CourseItem $item): ?CourseItem
    {
        if ($item->drip_after_item_id !== null) {
            // `published()` matters: an unpublished blocker is invisible to
            // the learner, so requiring it would deadlock — the same reason a
            // resource is stepped over. It also keeps this path in step with
            // predecessorMap(), which only ever sees published items.
            return CourseItem::query()
                ->where('course_id', $item->course_id)
                ->published()
                ->whereKey($item->drip_after_item_id)
                ->first();
        }

        return CourseItem::query()
            ->where('course_id', $item->course_id)
            ->published()
            ->where('position', '<', $item->position)
            ->whereNot('type', 'resource')
            ->orderByDesc('position')
            ->first();
    }

    /**
     * @param  Collection<int, CourseItem>  $ordered
     * @return Collection<int, CourseItem|null> keyed by course_item_id
     */
    private function predecessorMap(Collection $ordered): Collection
    {
        $byId = $ordered->keyBy('id');
        $map = collect();
        $lastCompletable = null;

        foreach ($ordered as $item) {
            // An explicit dependency wins over "the one before it", which is
            // what makes a branching or optional section expressible at all.
            $map[$item->id] = $item->drip_after_item_id !== null
                ? $byId->get($item->drip_after_item_id)
                : $lastCompletable;

            // A resource cannot be completed, so requiring one would deadlock
            // the rest of the course.
            if ($item->type->isCompletable()) {
                $lastCompletable = $item;
            }
        }

        return $map;
    }

    private function modeFor(Course $course): DripMode
    {
        $course->loadMissing('setting');
        $setting = $course->setting;

        // CreateCourse always writes a settings row, but a Course built
        // directly in a test or a fixture need not have one — and a missing
        // row must mean "no drip", never a fatal on the player's hot path.
        return $setting instanceof CourseSetting ? $setting->drip_mode : DripMode::None;
    }
}
