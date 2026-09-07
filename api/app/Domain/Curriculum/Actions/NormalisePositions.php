<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Actions;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites positions to 0..n-1 in display order.
 *
 * Called after any structural change that can leave gaps (delete, duplicate,
 * move between sections). Gaps are harmless for ordering but make "the item at
 * index 7" and progress percentages harder to reason about, so we keep the
 * sequence dense.
 */
final class NormalisePositions
{
    public function handle(Course $course): void
    {
        DB::transaction(function () use ($course): void {
            $sections = CourseSection::where('course_id', $course->id)
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            $globalPosition = 0;

            foreach ($sections->values() as $index => $section) {
                if ($section->position !== $index) {
                    $section->forceFill(['position' => $index])->save();
                }

                $items = CourseItem::where('section_id', $section->id)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get();

                foreach ($items as $item) {
                    if ($item->position !== $globalPosition) {
                        $item->forceFill(['position' => $globalPosition])->save();
                    }
                    $globalPosition++;
                }
            }
        });
    }
}
