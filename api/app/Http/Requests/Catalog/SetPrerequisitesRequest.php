<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

final class SetPrerequisitesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the course.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The numeric ref, matching the reorder endpoint and the item
            // picker: a whole-set write should not carry 10 uuids.
            'course_ids' => ['present', 'array', 'max:10'],
            'course_ids.*' => ['integer', 'min:1'],
        ];
    }
}
