<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Enums\CourseInstructorRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CourseInstructorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the course.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'exists:users,uuid'],
            'role' => [
                'sometimes',
                Rule::enum(CourseInstructorRole::class)->except(CourseInstructorRole::Owner),
            ],
            'revenue_share_bp' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }

    public function role(): CourseInstructorRole
    {
        return CourseInstructorRole::from(
            $this->string('role', CourseInstructorRole::CoInstructor->value)->value()
        );
    }
}
