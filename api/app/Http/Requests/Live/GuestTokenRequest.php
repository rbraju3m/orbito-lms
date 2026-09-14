<?php

declare(strict_types=1);

namespace App\Http\Requests\Live;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A guest's confirmation or manage token, in the BODY. Never a query string:
 * that is what proxies and access logs record, and the token is the only
 * credential a guest has.
 */
final class GuestTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The token is the authorization — see GuestToken.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:4000'],
        ];
    }

    public function token(): string
    {
        return (string) $this->input('token');
    }
}
