<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An invitation's token, in the BODY. Never a query string: that is what
 * proxies and access logs record, and the token is the credential.
 */
final class InvitationTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The token is the authorization — see AcceptInvitation.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:200'],
        ];
    }

    public function token(): string
    {
        return (string) $this->input('token');
    }
}
