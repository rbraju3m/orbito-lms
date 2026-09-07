<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

final class SuspendUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the target user.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'suspended' => ['required', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
