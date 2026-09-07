<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Studio;

use App\Domain\Catalog\Actions\SetCoursePrerequisites;
use App\Domain\Catalog\Models\Course;
use App\Http\Requests\Catalog\SetPrerequisitesRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CoursePrerequisiteController
{
    /**
     * Replaces the whole set. There is no add/remove pair on purpose — a delta
     * lets two authors interleave into a set neither asked for (Phase 5).
     */
    public function update(
        SetPrerequisitesRequest $request,
        Course $course,
        SetCoursePrerequisites $action,
    ): JsonResponse {
        Gate::authorize('manageSettings', $course);

        /** @var list<int> $ids */
        $ids = $request->validated('course_ids', []);

        $updated = $action->handle($course, $ids);

        return ApiResponse::ok(
            $updated->prerequisites
                ->map(fn (Course $prerequisite): array => [
                    'id' => $prerequisite->uuid,
                    'ref' => $prerequisite->id,
                    'slug' => $prerequisite->slug,
                    'title' => $prerequisite->title,
                ])
                ->values()
                ->all()
        );
    }
}
