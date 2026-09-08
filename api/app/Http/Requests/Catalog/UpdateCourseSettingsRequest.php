<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Curriculum\Enums\DripMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCourseSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the course.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enable_qa' => ['sometimes', 'boolean'],
            'enable_reviews' => ['sometimes', 'boolean'],
            'enable_notes' => ['sometimes', 'boolean'],
            'enable_certificate' => ['sometimes', 'boolean'],
            'moderate_reviews' => ['sometimes', 'boolean'],
            'max_students' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'enrollment_expires_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'drip_mode' => ['sometimes', Rule::enum(DripMode::class)],
            'retake_allowed' => ['sometimes', 'boolean'],
            'reset_progress_allowed' => ['sometimes', 'boolean'],
            'video_completion_threshold' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
