<?php

declare(strict_types=1);

namespace App\Http\Requests\Curriculum;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the course.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:1', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
