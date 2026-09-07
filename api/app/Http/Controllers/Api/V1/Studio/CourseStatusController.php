<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Studio;

use App\Domain\Catalog\Actions\ChangeCourseStatus;
use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Models\Course;
use App\Http\Requests\Catalog\CourseStatusRequest;
use App\Http\Resources\Catalog\CourseResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Lifecycle transitions as sub-resources, not a verb in a query string
 * (docs/API.md §1). Each one authorizes against its own policy method.
 */
final class CourseStatusController
{
    public function __construct(private readonly ChangeCourseStatus $action) {}

    public function publish(CourseStatusRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('publish', $course);

        return $this->respond($request, $course, CourseStatus::Published);
    }

    public function submitForReview(CourseStatusRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('submitForReview', $course);

        return $this->respond($request, $course, CourseStatus::InReview);
    }

    /** Sends a submitted course back to the author. */
    public function rejectReview(CourseStatusRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('reviewSubmission', $course);

        return $this->respond($request, $course, CourseStatus::Draft);
    }

    public function approveReview(CourseStatusRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('reviewSubmission', $course);

        return $this->respond($request, $course, CourseStatus::Published);
    }

    public function archive(CourseStatusRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('archive', $course);

        return $this->respond($request, $course, CourseStatus::Archived);
    }

    /** Pulls a live course back to draft without losing it. */
    public function unpublish(CourseStatusRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('publish', $course);

        return $this->respond($request, $course, CourseStatus::Draft);
    }

    private function respond(CourseStatusRequest $request, Course $course, CourseStatus $target): JsonResponse
    {
        $updated = $this->action->handle($course, $target, $request->user(), $request->note());

        return ApiResponse::ok(CourseResource::make($updated->load([
            'category', 'owner', 'thumbnail', 'tags', 'detail', 'setting', 'instructors.user',
        ])));
    }
}
