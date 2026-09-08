<?php

declare(strict_types=1);

namespace App\Http\Requests\Live;

use App\Domain\Live\Enums\CohortStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCohortRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            // Null means uncapped. Zero would mean a cohort nobody can join,
            // which nobody means to create.
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'enrollment_deadline' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::enum(CohortStatus::class)],
        ];
    }
}
