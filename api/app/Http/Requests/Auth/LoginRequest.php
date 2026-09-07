<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
            // A stateful first-party request (SPA origin) gets a cookie session.
            // Any other client MUST name its device so we can issue — and later
            // revoke — a token for it. Silently returning neither would leave the
            // caller believing it is authenticated when it is not.
            'device_name' => [$this->hasSession() ? 'sometimes' : 'required', 'string', 'max:120'],
        ];
    }
}
