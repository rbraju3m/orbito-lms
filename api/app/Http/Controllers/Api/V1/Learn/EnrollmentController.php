<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learn;

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Actions\EnrollInCourse;
use App\Domain\Progress\Queries\ContinueLearningQuery;
use App\Http\Resources\Catalog\CourseListResource;
use App\Http\Resources\Progress\CourseProgressResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EnrollmentController
{
    /** Free courses only. A paid course goes through checkout (ADR-05). */
    public function store(Request $request, Course $course, EnrollInCourse $action): JsonResponse
    {
        $enrollment = $action->handle($request->user(), $course);

        return ApiResponse::created([
            'id' => $enrollment->uuid,
            'status' => $enrollment->status->value,
            'enrolled_at' => $enrollment->enrolled_at->toIso8601String(),
            'expires_at' => $enrollment->expires_at?->toIso8601String(),
        ]);
    }

    /** "Continue learning" — one indexed read (ADR-02). */
    public function continueLearning(Request $request, ContinueLearningQuery $query): JsonResponse
    {
        $rows = $query->forUser($request->user());

        return ApiResponse::ok($rows->map(fn ($progress) => [
            'course' => [
                'id' => $progress->course->uuid,
                'slug' => $progress->course->slug,
                'title' => $progress->course->title,
                'subtitle' => $progress->course->subtitle,
                'thumbnail_url' => $progress->course->thumbnail?->publicUrl(),
            ],
            'progress' => CourseProgressResource::make($progress)->toArray($request),
            'resume_item_id' => $progress->lastItem?->uuid,
        ])->all());
    }

    public function myCourses(Request $request, ContinueLearningQuery $query): JsonResponse
    {
        $perPage = min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );

        $rows = $query->enrolledCourses(
            $request->user(),
            $request->string('filter', 'all')->value(),
            $perPage,
        );

        return ApiResponse::ok($rows->through(fn ($progress) => [
            'course' => CourseListResource::make($progress->course)->toArray($request),
            'progress' => CourseProgressResource::make($progress)->toArray($request),
            'enrollment_status' => $progress->enrollment?->status->value,
        ]));
    }
}
