<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\Course;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a bundle's contents with the whole collection given, in order.
 *
 * The WHOLE list, never a delta — the rule `ReorderCurriculum` established in
 * Phase 5. A delta lets two people editing the same bundle interleave into a
 * state neither of them asked for, and here that state is what somebody gets
 * charged for.
 *
 * Duplicates are dropped rather than rejected: the unique index would refuse
 * the write, and a client that sent the same course twice meant it once.
 */
final class SetBundleCourses
{
    /** @param  list<int>  $courseIds */
    public function handle(Bundle $bundle, array $courseIds): Bundle
    {
        $courseIds = array_values(array_unique($courseIds));

        // Silently dropping an id that does not exist would leave the author
        // looking at a bundle missing a course they just added, with no reason
        // given. The Form Request rejects unknown ids; this is the backstop
        // for one deleted between validation and here.
        $known = Course::query()->whereIn('id', $courseIds)->pluck('id')->all();
        $ordered = array_values(array_filter(
            $courseIds,
            static fn (int $id): bool => in_array($id, $known, true),
        ));

        DB::transaction(function () use ($bundle, $ordered): void {
            $bundle->items()->delete();

            foreach ($ordered as $position => $courseId) {
                $bundle->items()->create([
                    'course_id' => $courseId,
                    'position' => $position,
                ]);
            }
        });

        return $bundle->load('courses');
    }
}
