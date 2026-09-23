<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Domain\Identity\Data\RegisterUserData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Registration by invitation. The same shape as `RegisterRequest` minus the
 * email address, which comes from the invitation and is never accepted from
 * the body — following the link proved that mailbox, not another one.
 */
final class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The token is the authorization — see AcceptInvitation.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'academy' => ['required', 'string', 'max:100'],
            'token' => ['required', 'string', 'max:200'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(config('orbito.locales.supported'))],
            // See RegisterRequest: a non-SPA client must name its device.
            'device_name' => [$this->hasSession() ? 'sometimes' : 'required', 'string', 'max:120'],
        ];
    }

    public function token(): string
    {
        return (string) $this->input('token');
    }

    /** The email here is a placeholder: `AcceptInvitation` takes the invitation's. */
    public function toData(): RegisterUserData
    {
        return new RegisterUserData(
            name: $this->string('name')->trim()->value(),
            email: '',
            password: $this->string('password')->value(),
            timezone: $this->string('timezone', 'UTC')->value(),
            // Absent is "not chosen": the academy's default applies (docs/I18N.md).
            locale: $this->filled('locale') ? $this->string('locale')->value() : null,
        );
    }
}
