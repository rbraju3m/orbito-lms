<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Studio;

use App\Domain\Catalog\Actions\ManageCourseInstructors;
use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;
use App\Http\Requests\Catalog\CourseInstructorRequest;
use App\Http\Resources\Catalog\CourseResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CourseInstructorController
{
    public function __construct(private readonly ManageCourseInstructors $action) {}

    public function store(CourseInstructorRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('manageInstructors', $course);

        $user = User::where('uuid', $request->string('user_id')->value())->firstOrFail();

        $this->action->add(
            $course,
            $user,
            $request->role(),
            $request->has('revenue_share_bp') ? $request->integer('revenue_share_bp') : null,
        );

        return ApiResponse::created(CourseResource::make($course->fresh(['instructors.user'])));
    }

    public function destroy(Course $course, User $user): JsonResponse
    {
        Gate::authorize('manageInstructors', $course);

        $this->action->remove($course, $user);

        return ApiResponse::noContent();
    }
}
