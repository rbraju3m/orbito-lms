<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Domain\Analytics\Data\DateRange;
use App\Domain\Analytics\Queries\CourseSeries;
use App\Domain\Analytics\Queries\FunnelQuery;
use App\Domain\Analytics\Queries\InstructorSeries;
use App\Domain\Analytics\Queries\PlatformOverview;
use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;
use App\Http\Resources\Analytics\CourseLeaderboardResource;
use App\Http\Resources\Analytics\FunnelItemResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The dashboards. Every one of them reads ROLLUPS ONLY (ADR-08).
 *
 * There is deliberately no endpoint that reads `analytics_events`. The log is
 * a write path and a rebuild source; exposing it would let one dashboard ask a
 * question the rollups cannot answer, and the next release would have two
 * definitions of the same metric.
 */
final class AnalyticsController
{
    public function overview(Request $request, PlatformOverview $overview, CourseSeries $courses): JsonResponse
    {
        Gate::authorize('view-platform-analytics');

        $range = $this->range($request);
        $result = $overview->handle($range);

        return ApiResponse::ok([
            'range' => $range->toArray(),
            ...$result,
            // The leaderboard rides along: an overview with no "which courses?"
            // is a number nobody can act on, and it is one grouped query.
            'top_courses' => CourseLeaderboardResource::collection(
                $courses->leaderboard($range, null),
            )->resolve($request),
        ]);
    }

    public function course(Request $request, Course $course, CourseSeries $series): JsonResponse
    {
        Gate::authorize('view-course-analytics', $course);

        $range = $this->range($request);

        return ApiResponse::ok([
            'range' => $range->toArray(),
            'course' => ['id' => $course->uuid, 'title' => $course->title, 'slug' => $course->slug],
            ...$series->forCourse($course, $range),
        ]);
    }

    /**
     * The stall heatmap.
     *
     * No date range: a funnel is the present state of everybody enrolled, not
     * a window. `computed_at` tells the reader how fresh that state is.
     */
    public function funnel(Request $request, Course $course, FunnelQuery $funnel): JsonResponse
    {
        Gate::authorize('view-course-analytics', $course);

        return ApiResponse::ok([
            'course' => ['id' => $course->uuid, 'title' => $course->title],
            'items' => FunnelItemResource::collection($funnel->forCourse($course))->resolve($request),
        ]);
    }

    /**
     * One instructor's own numbers, or anybody's for a platform reader.
     *
     * `{user}` binds by uuid and resolves globally, so membership of the
     * caller's own identity is checked HERE — the §13 rule about unscoped
     * bindings. Without it, `analytics.view.own` would read as "view anybody's
     * analytics", which is the opposite of what it says.
     */
    public function instructor(Request $request, User $user, InstructorSeries $series): JsonResponse
    {
        $caller = $request->user();

        if ($user->id !== $caller->id) {
            Gate::authorize('view-platform-analytics');
        } elseif (! $caller->hasPermission('analytics.view.own')) {
            Gate::authorize('view-platform-analytics');
        }

        $range = $this->range($request);

        return ApiResponse::ok([
            'range' => $range->toArray(),
            'instructor' => ['id' => $user->uuid, 'name' => $user->name],
            ...$series->forInstructor($user->id, $range),
        ]);
    }

    private function range(Request $request): DateRange
    {
        return DateRange::make(
            $request->query('from') === null ? null : (string) $request->query('from'),
            $request->query('to') === null ? null : (string) $request->query('to'),
        );
    }
}
