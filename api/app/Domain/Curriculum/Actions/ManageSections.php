<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Actions;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Events\CurriculumChanged;
use App\Domain\Curriculum\Models\CourseSection;
use Illuminate\Support\Facades\DB;

final class ManageSections
{
    public function create(Course $course, string $title, ?string $description = null): CourseSection
    {
        $section = $course->sections()->create([
            'title' => $title,
            'description' => $description,
            'position' => (int) $course->sections()->max('position') + 1,
        ]);

        CurriculumChanged::dispatch($course);

        return $section;
    }

    /** @param  array<string, mixed>  $attributes */
    public function update(CourseSection $section, array $attributes): CourseSection
    {
        $section->fill($attributes)->save();

        return $section;
    }

    /**
     * Deleting a section takes its items with it, and closes the gap in the
     * course-global positions so prev/next stays contiguous.
     */
    public function delete(CourseSection $section): void
    {
        $course = $section->loadMissing('course')->course;

        DB::transaction(function () use ($section): void {
            $section->items()->delete();
            $section->delete();
        });

        app(NormalisePositions::class)->handle($course);

        CurriculumChanged::dispatch($course);
    }

    public function duplicate(CourseSection $section): CourseSection
    {
        $course = $section->loadMissing('course')->course;

        $copy = DB::transaction(function () use ($section, $course): CourseSection {
            $copy = $course->sections()->create([
                'title' => $section->title.' (copy)',
                'description' => $section->description,
                'position' => (int) $course->sections()->max('position') + 1,
            ]);

            $duplicateItem = app(ManageItems::class);

            // Eager-load what duplicate() needs: one query for the batch, not
            // one per item.
            foreach ($section->items()->with(['course', 'section', 'itemable'])->get() as $item) {
                $duplicateItem->duplicate($item, $copy, dispatchEvent: false);
            }

            return $copy;
        });

        app(NormalisePositions::class)->handle($course);

        CurriculumChanged::dispatch($course);

        return $copy;
    }
}
