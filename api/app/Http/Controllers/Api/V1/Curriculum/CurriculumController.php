<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Curriculum;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Actions\ReorderCurriculum;
use App\Domain\Curriculum\Queries\CurriculumTreeQuery;
use App\Http\Requests\Curriculum\ReorderCurriculumRequest;
use App\Http\Resources\Curriculum\CourseSectionResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CurriculumController
{
    public function show(Course $course, CurriculumTreeQuery $query): JsonResponse
    {
        Gate::authorize('view-curriculum', $course);

        return ApiResponse::ok(CourseSectionResource::collection($query->forAuthor($course)));
    }

    /**
     * The only endpoint that writes `position`. Idempotent: sending the same
     * tree twice produces the same result.
     */
    public function reorder(
        ReorderCurriculumRequest $request,
        Course $course,
        ReorderCurriculum $action,
        CurriculumTreeQuery $query,
    ): JsonResponse {
        Gate::authorize('reorder-curriculum', $course);

        $action->handle($course, $request->tree());

        return ApiResponse::ok(CourseSectionResource::collection($query->forAuthor($course)));
    }
}
