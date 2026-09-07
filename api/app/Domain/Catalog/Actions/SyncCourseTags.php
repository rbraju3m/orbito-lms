<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseTag;
use Illuminate\Support\Facades\DB;

final class SyncCourseTags
{
    /** @param  list<string>  $names */
    public function handle(Course $course, array $names): void
    {
        $ids = [];

        foreach (array_slice(array_unique(array_filter(array_map('trim', $names))), 0, 15) as $name) {
            $ids[] = CourseTag::findOrCreateByName($name)->id;
        }

        $changes = $course->tags()->sync($ids);

        // usage_count drives tag suggestions and the catalogue filter, so it is
        // maintained here rather than counted on read.
        $this->adjustUsage($changes['attached'], +1);
        $this->adjustUsage($changes['detached'], -1);
    }

    /** @param  list<int|string>  $tagIds */
    private function adjustUsage(array $tagIds, int $delta): void
    {
        if ($tagIds === []) {
            return;
        }

        CourseTag::whereIn('id', $tagIds)->update([
            'usage_count' => DB::raw('GREATEST(0, usage_count + '.(int) $delta.')'),
        ]);
    }
}
