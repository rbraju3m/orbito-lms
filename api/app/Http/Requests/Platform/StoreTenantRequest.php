<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The route is behind the super_admin middleware.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The slug names the academy for its whole life and cannot be
            // changed without re-pointing everything, so it is validated
            // tightly here rather than sanitised later.
            'slug' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'unique:mysql.tenants,slug'],
            'name' => ['required', 'string', 'min:2', 'max:180'],

            'owner_name' => ['required', 'string', 'min:2', 'max:255'],
            // Central table, explicit connection: the default one is an
            // academy's schema inside a request.
            'owner_email' => ['required', 'email:rfc', 'max:255', 'unique:mysql.users,email'],
            'owner_password' => ['required', 'string', Password::defaults()],

            'support_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'plan' => ['sometimes', 'nullable', 'string', 'exists:mysql.plans,slug'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may contain lowercase letters, numbers and single hyphens only.',
        ];
    }
}
