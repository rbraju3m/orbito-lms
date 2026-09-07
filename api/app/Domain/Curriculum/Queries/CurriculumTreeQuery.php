<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Queries;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseSection;
use Illuminate\Support\Collection;

/**
 * The curriculum read model.
 *
 * Two queries regardless of course size — sections, then every item for the
 * course in one go, grouped in PHP. The audited reference product needs a join
 * across heterogeneous post types for the same answer.
 */
final class CurriculumTreeQuery
{
    /** @return Collection<int, CourseSection> */
    public function forAuthor(Course $course): Collection
    {
        return CourseSection::query()
            ->where('course_id', $course->id)
            ->with(['items' => fn ($q) => $q->with('itemable')->orderBy('position')])
            ->orderBy('position')
            ->get();
    }

    /**
     * The learner-facing tree: unpublished items are omitted entirely.
     *
     * @return Collection<int, CourseSection>
     */
    public function forLearner(Course $course): Collection
    {
        return CourseSection::query()
            ->where('course_id', $course->id)
            ->with(['items' => fn ($q) => $q->published()->orderBy('position')])
            ->orderBy('position')
            ->get()
            // A section whose every item is unpublished is noise to a learner.
            ->filter(fn (CourseSection $section) => $section->items->isNotEmpty())
            ->values();
    }
}
