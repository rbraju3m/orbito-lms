<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicSite;

use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Queries\CourseCatalogQuery;
use App\Http\Resources\Catalog\CourseListResource;
use App\Http\Resources\Catalog\CourseResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An academy's courses, to somebody who has no account.
 *
 * The SAME query and the SAME resources the members-only catalogue uses, and
 * that is the point: a sales page that renders a course differently from the
 * catalogue is a second definition of what a course is, and the two would
 * drift in exactly the fields a buyer decides on. Both resources compute
 * their viewer-scoped keys from `$request->user()`, which is null here, so
 * nothing has to be stripped — `is_wishlisted`, the staff settings block and
 * the publish checklist simply do not appear (§ Patterns established in Phase
 * 12: per-row capabilities are not in a list, and none of them are a
 * stranger's).
 *
 * What the anonymous reader may see is decided by SCOPES, not by this class:
 * `listed()` for the catalogue — published and public — and `live()` for a
 * direct link, which admits an unlisted course somebody was sent and still
 * refuses a private or draft one.
 */
final class CourseController
{
    public function index(Request $request, CourseCatalogQuery $query): JsonResponse
    {
        $perPage = min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );

        $filters = $request->only([
            'q', 'category', 'tags', 'level', 'language', 'price', 'min_rating', 'instructor', 'sort',
        ]);

        return ApiResponse::ok(CourseListResource::collection($query->paginate($filters, $perPage)));
    }

    /**
     * The sales page, addressed by slug.
     *
     * 404 rather than 403 for anything not live, so the status code cannot
     * confirm that a draft course by that name exists — the same answer the
     * members-only page gives.
     */
    public function show(Request $request, string $academy, string $slug): JsonResponse
    {
        $course = Course::query()
            ->live()
            ->where('slug', $slug)
            ->with(['category', 'owner', 'thumbnail', 'tags', 'detail', 'instructors.user', 'product.prices'])
            ->first();

        if ($course === null) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::ok(CourseResource::make($course));
    }
}
