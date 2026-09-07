<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Studio;

use App\Domain\Catalog\Actions\CreateCourse;
use App\Domain\Catalog\Actions\DeleteCourse;
use App\Domain\Catalog\Actions\UpdateCourse;
use App\Domain\Catalog\Models\Course;
use App\Http\Requests\Catalog\StoreCourseRequest;
use App\Http\Requests\Catalog\UpdateCourseRequest;
use App\Http\Resources\Catalog\CourseListResource;
use App\Http\Resources\Catalog\CourseResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class CourseController
{
    /** Courses the caller owns, co-instructs, or may edit platform-wide. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('create', Course::class);

        $user = $request->user();

        $perPage = min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );

        $courses = Course::query()
            ->with(['category', 'owner', 'thumbnail'])
            ->unless(
                $user->hasPermission('course.update.any'),
                fn ($q) => $q->where(function ($query) use ($user): void {
                    $query->where('owner_id', $user->id)
                        ->orWhereHas('instructors', fn ($i) => $i->where('user_id', $user->id));
                }),
            )
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')->value()),
            )
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = $request->string('q')->value();
                $q->where('title', 'like', "%{$term}%");
            })
            ->latest('updated_at')
            ->paginate($perPage);

        return ApiResponse::ok(CourseListResource::collection($courses));
    }

    public function store(StoreCourseRequest $request, CreateCourse $action): JsonResponse
    {
        $course = $action->handle($request->user(), $request->toData());

        return ApiResponse::created(CourseResource::make($course));
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('viewUnpublished', $course);

        return ApiResponse::ok(CourseResource::make($course->load([
            'category', 'owner', 'thumbnail', 'tags', 'detail', 'setting', 'instructors.user',
        ])));
    }

    public function update(UpdateCourseRequest $request, Course $course, UpdateCourse $action): JsonResponse
    {
        Gate::authorize('update', $course);

        $updated = $action->handle($course, $request->toData(), $request->suppliedKeys());

        return ApiResponse::ok(CourseResource::make($updated));
    }

    public function destroy(Course $course, DeleteCourse $action): JsonResponse
    {
        Gate::authorize('delete', $course);

        $action->handle($course);

        return ApiResponse::noContent();
    }
}
