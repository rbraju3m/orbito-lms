<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the Role being granted.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'exists:roles,key'],
            // Course-scoped roles carry a scope. The type is an alias from the
            // morph map, never a class name from the client.
            'scope_type' => ['sometimes', 'nullable', 'string', Rule::in(['course'])],
            'scope_id' => ['required_with:scope_type', 'nullable', 'integer', 'min:1'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
