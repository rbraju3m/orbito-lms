<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

final class CourseStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes per transition.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['sometimes', 'nullable', 'string', 'max:1000']];
    }

    public function note(): ?string
    {
        return $this->string('note')->value() ?: null;
    }
}
