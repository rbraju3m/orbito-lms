<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Queries;

use App\Domain\Analytics\Models\ItemFunnel;
use App\Domain\Catalog\Models\Course;
use Illuminate\Support\Collection;
use stdClass;

/**
 * The stall heatmap for one course.
 *
 * Returned in CURRICULUM ORDER, not worst-first, even though the index is
 * built for worst-first. A heatmap is read against the shape of the course —
 * "they drop out after the third video" is the insight, and a list sorted by
 * severity destroys exactly the adjacency that makes it visible. The UI can
 * still highlight the worst; it cannot reconstruct the order.
 */
final class FunnelQuery
{
    /** @return Collection<int, stdClass> */
    public function forCourse(Course $course): Collection
    {
        return ItemFunnel::query()
            ->join('course_items', 'course_items.id', '=', 'analytics_item_funnel.course_item_id')
            ->leftJoin('course_sections', 'course_sections.id', '=', 'course_items.section_id')
            ->where('analytics_item_funnel.course_id', $course->id)
            ->selectRaw('analytics_item_funnel.*')
            ->selectRaw('course_items.uuid as item_uuid, course_items.title, course_items.type')
            ->selectRaw('course_items.position, course_sections.title as section_title')
            // `position` is course-global and dense (§11), so this alone is
            // the curriculum order — no secondary sort on the section needed.
            ->orderBy('course_items.position')
            ->toBase()
            ->get();
    }
}
