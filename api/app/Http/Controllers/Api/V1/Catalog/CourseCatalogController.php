<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Queries\CourseCatalogQuery;
use App\Http\Requests\Catalog\CatalogCoursesRequest;
use App\Http\Resources\Catalog\CourseListResource;
use App\Http\Resources\Catalog\CourseResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CourseCatalogController
{
    public function index(CatalogCoursesRequest $request, CourseCatalogQuery $query): JsonResponse
    {
        return ApiResponse::ok(CourseListResource::collection(
            $query->paginate($request->catalogFilters(), $request->perPage()),
        ));
    }

    /**
     * Public course page, addressed by slug.
     *
     * A course the caller may not see returns 404, never 403: the status code
     * must not confirm that an unpublished course by that name exists.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $course = Course::where('slug', $slug)
            ->with(['category', 'owner', 'thumbnail', 'tags', 'detail', 'instructors.user', 'setting', 'product.prices'])
            ->first();

        if ($course === null || $request->user()?->can('view', $course) === false) {
            throw new NotFoundHttpException;
        }

        if ($request->user() === null && ! $course->status->isLive()) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::ok(CourseResource::make($course));
    }
}
