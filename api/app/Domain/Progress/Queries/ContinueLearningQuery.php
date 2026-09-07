<?php

declare(strict_types=1);

namespace App\Domain\Progress\Queries;

use App\Domain\Identity\Models\User;
use App\Domain\Progress\Models\CourseProgress;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The single most-hit learner query.
 *
 * ONE indexed read against course_progress — no walking the curriculum, no
 * counting completed items, no per-course aggregation. This is ADR-02's
 * payoff, and the direct answer to the audit's worst finding.
 */
final class ContinueLearningQuery
{
    /** @return Collection<int, CourseProgress> */
    public function forUser(User $user, int $limit = 4): Collection
    {
        return CourseProgress::query()
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->whereNotNull('last_activity_at')
            ->with(['course:id,uuid,slug,title,subtitle,thumbnail_media_id', 'course.thumbnail', 'lastItem'])
            ->orderByDesc('last_activity_at')
            ->limit($limit)
            ->get();
    }

    /** @return LengthAwarePaginator<int, CourseProgress> */
    public function enrolledCourses(User $user, string $filter, int $perPage): LengthAwarePaginator
    {
        return CourseProgress::query()
            ->where('user_id', $user->id)
            ->when($filter === 'in_progress', fn ($q) => $q->whereNull('completed_at'))
            ->when($filter === 'completed', fn ($q) => $q->whereNotNull('completed_at'))
            ->with([
                'course:id,uuid,slug,title,subtitle,level,locale,status,visibility,pricing_model,'
                    .'thumbnail_media_id,item_count,total_duration_seconds,enrollment_count,'
                    .'rating_avg,rating_count,published_at,updated_at,owner_id,category_id',
                'course.thumbnail',
                'enrollment',
            ])
            ->orderByDesc('last_activity_at')
            ->orderByDesc('enrollment_id')
            ->paginate($perPage);
    }
}
