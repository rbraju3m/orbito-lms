<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Domain\Catalog\Models\Course;
use App\Domain\Live\Actions\JoinCohort;
use App\Domain\Live\Models\Cohort;
use App\Http\Requests\Live\StoreCohortRequest;
use App\Http\Resources\Live\CohortResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Scheduled runs of a course.
 *
 * Drafts are visible only to people who can manage them — filtered by what the
 * reader may see rather than by a query parameter, the same rule as
 * announcements and reviews.
 */
final class CohortController
{
    public function index(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('view', $course);

        $canManage = Gate::allows('manage-live-for-course', $course);

        $cohorts = Cohort::query()
            ->withCount(['sessions', 'enrollments'])
            ->where('course_id', $course->id)
            ->unless($canManage, fn ($query) => $query->visible())
            ->orderBy('starts_at')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(
            CohortResource::collection($cohorts)
                ->additional(['meta' => ['can_manage' => $canManage]]),
        );
    }

    public function store(StoreCohortRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('manage-live-for-course', $course);

        $cohort = Cohort::create([
            'course_id' => $course->id,
            ...$request->validated(),
        ]);

        return ApiResponse::created(CohortResource::make($cohort->loadCount(['sessions', 'enrollments'])));
    }

    public function update(StoreCohortRequest $request, Cohort $cohort): JsonResponse
    {
        Gate::authorize('manage-live-for-course', $cohort->loadMissing('course')->course);

        $cohort->fill($request->validated())->save();

        return ApiResponse::ok(CohortResource::make(
            $cohort->fresh()->loadCount(['sessions', 'enrollments']),
        ));
    }

    public function destroy(Cohort $cohort): JsonResponse
    {
        Gate::authorize('manage-live-for-course', $cohort->loadMissing('course')->course);

        $cohort->delete();

        return ApiResponse::noContent();
    }

    /**
     * Joining a run.
     *
     * Everything enrolment already enforces — payment, prerequisites, the
     * course seat limit, the duplicate check — still applies: this path adds
     * a cohort, it does not open a second door into the course.
     */
    public function join(Request $request, Cohort $cohort, JoinCohort $action): JsonResponse
    {
        $enrollment = $action->handle($request->user(), $cohort);

        return ApiResponse::created([
            'enrollment_id' => $enrollment->uuid,
            'cohort' => CohortResource::make($cohort->fresh())->resolve($request),
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );
    }
}
