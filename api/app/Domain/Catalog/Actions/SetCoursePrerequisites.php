<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\PrerequisiteRejected;
use App\Domain\Catalog\Models\Course;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a course's prerequisite set wholesale.
 *
 * Whole collection, never a delta — the Phase 5 rule. A delta lets two authors
 * editing the same course interleave into a set neither of them asked for.
 */
final class SetCoursePrerequisites
{
    /** More than this and the course page becomes a wall of blockers. */
    private const MAX = 10;

    /**
     * @param  list<int>  $courseIds
     */
    public function handle(Course $course, array $courseIds): Course
    {
        $ids = array_values(array_unique(array_map('intval', $courseIds)));

        if (count($ids) > self::MAX) {
            throw PrerequisiteRejected::tooMany(self::MAX);
        }

        if (in_array($course->id, $ids, true)) {
            throw PrerequisiteRejected::selfReference();
        }

        $existing = Course::query()->whereKey($ids)->pluck('id')->all();
        $missing = array_diff($ids, $existing);

        if ($missing !== []) {
            throw PrerequisiteRejected::unknownCourse();
        }

        $this->assertNoCycle($course->id, $ids);

        DB::transaction(function () use ($course, $ids): void {
            $course->prerequisites()->sync(
                collect($ids)
                    ->mapWithKeys(fn (int $id, int $position) => [$id => ['position' => $position]])
                    ->all(),
            );
        });

        return $course->load('prerequisites');
    }

    /**
     * A cycle is a course that can never be entered: A needs B, B needs A, and
     * both are permanently shut. Walk the existing graph forwards from each
     * proposed prerequisite; if we arrive back at this course, refuse.
     *
     * @param  list<int>  $proposed
     */
    private function assertNoCycle(int $courseId, array $proposed): void
    {
        $seen = [];
        $queue = $proposed;

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current === $courseId) {
                throw PrerequisiteRejected::cycle();
            }

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            $queue = [...$queue, ...DB::table('course_prerequisites')
                ->where('course_id', $current)
                ->pluck('prerequisite_course_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all()];
        }
    }
}
