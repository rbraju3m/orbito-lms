<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Studio;

use App\Domain\Catalog\Actions\UpdateCourseSettings;
use App\Domain\Catalog\Models\Course;
use App\Http\Requests\Catalog\UpdateCourseSettingsRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CourseSettingsController
{
    public function update(
        UpdateCourseSettingsRequest $request,
        Course $course,
        UpdateCourseSettings $action,
    ): JsonResponse {
        Gate::authorize('manageSettings', $course);

        $setting = $action->handle($course, $request->validated());

        return ApiResponse::ok($setting->only([
            'enable_qa', 'enable_reviews', 'moderate_reviews', 'enable_notes', 'enable_certificate',
            'max_students', 'enrollment_expires_days', 'drip_mode',
            'retake_allowed', 'reset_progress_allowed', 'video_completion_threshold',
        ]));
    }
}
