<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Actions;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Events\CurriculumChanged;
use App\Domain\Curriculum\Exceptions\CurriculumRejected;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY write path for `position` (ADR-01).
 *
 * Takes the whole tree, validates it is a permutation of what the course
 * currently holds, and rewrites every position in one transaction. Accepting
 * partial moves instead would make concurrent drags silently interleave.
 */
final class ReorderCurriculum
{
    /**
     * @param  list<array{id: int, item_ids: list<int>}>  $sections
     */
    public function handle(Course $course, array $sections): void
    {
        $currentSectionIds = CourseSection::where('course_id', $course->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values();

        $currentItemIds = CourseItem::where('course_id', $course->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values();

        $givenSectionIds = collect($sections)->pluck('id')->map(fn ($id) => (int) $id);
        $givenItemIds = collect($sections)->flatMap(fn (array $s) => $s['item_ids'])
            ->map(fn ($id) => (int) $id);

        // Duplicates inside the payload would pass a naive count check.
        if ($givenSectionIds->duplicates()->isNotEmpty() || $givenItemIds->duplicates()->isNotEmpty()) {
            throw CurriculumRejected::reorderNotAPermutation(
                $currentItemIds->count(),
                $givenItemIds->count(),
            );
        }

        if ($givenSectionIds->sort()->values()->all() !== $currentSectionIds->all()
            || $givenItemIds->sort()->values()->all() !== $currentItemIds->all()) {
            throw CurriculumRejected::reorderNotAPermutation(
                $currentItemIds->count(),
                $givenItemIds->count(),
            );
        }

        DB::transaction(function () use ($course, $sections): void {
            $globalPosition = 0;

            foreach ($sections as $sectionIndex => $section) {
                CourseSection::where('id', $section['id'])
                    ->where('course_id', $course->id)
                    ->update(['position' => $sectionIndex, 'updated_at' => now()]);

                foreach ($section['item_ids'] as $itemId) {
                    CourseItem::where('id', $itemId)
                        ->where('course_id', $course->id)
                        ->update([
                            // Position is course-global and strictly increasing
                            // in display order, so prev/next stays one query.
                            'position' => $globalPosition++,
                            'section_id' => $section['id'],
                            'updated_at' => now(),
                        ]);
                }
            }
        });

        CurriculumChanged::dispatch($course);
    }
}
