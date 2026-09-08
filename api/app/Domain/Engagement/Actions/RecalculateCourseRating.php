<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Actions;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Models\Review;

/**
 * Writes `courses.rating_avg` and `rating_count` from the reviews table.
 *
 * THE POINT OF PHASE 12, and the exit criterion: a course card must never
 * compute `AVG(rating)`. A catalogue page renders twenty cards; twenty
 * aggregate scans on a hot read path is the anti-pattern this whole design
 * avoids (CLAUDE.md §10).
 *
 * So this runs on WRITE — a review published, edited, rejected or deleted —
 * and the nightly reconciliation runs it again for every course. Drift is a
 * bug, not something to live with.
 *
 * Deliberately one SQL aggregate over an indexed column, not a load-and-sum
 * in PHP: a course with 10,000 reviews must not pull 10,000 rows into memory
 * to update two columns.
 */
final class RecalculateCourseRating
{
    public function handle(Course $course): Course
    {
        /** @var object{count: int, average: string|null} $row */
        $row = Review::query()
            ->counted()
            ->where('course_id', $course->id)
            ->selectRaw('COUNT(*) as count, AVG(rating) as average')
            ->first();

        $count = (int) $row->count;

        $course->forceFill([
            'rating_count' => $count,
            /*
             * Zero, not null, when there are no reviews. `rating_avg` is a
             * NOT NULL decimal and a course with no reviews genuinely has no
             * rating — the COUNT beside it is what tells a card to render
             * "not rated yet" rather than a zero-star course.
             */
            'rating_avg' => $count === 0 ? 0 : round((float) $row->average, 2),
        ])->save();

        return $course;
    }

    /** By id, for the event path where the Course may not be loaded. */
    public function forCourseId(int $courseId): void
    {
        $course = Course::find($courseId);

        if ($course !== null) {
            $this->handle($course);
        }
    }
}
