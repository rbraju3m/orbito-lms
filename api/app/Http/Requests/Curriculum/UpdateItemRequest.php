<?php

declare(strict_types=1);

namespace App\Http\Requests\Curriculum;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:1', 'max:180'],
            'is_preview' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'duration_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
        ];
    }
}
