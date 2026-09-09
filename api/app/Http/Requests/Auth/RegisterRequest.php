<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Domain\Identity\Data\RegisterUserData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * WHICH academy. Required, because tenancy resolves from the
             * authenticated user and this route has none — without it the
             * account belongs nowhere.
             *
             * Shape only. Whether the academy exists, is open, and accepts
             * sign-ups is decided by `ResolveSignupAcademy`, so that "no such
             * academy" and "invitation only" can be different answers instead
             * of one `exists` failure.
             */
            'academy' => ['required', 'string', 'max:100'],

            'name' => ['required', 'string', 'min:2', 'max:120'],
            // `rfc` only, deliberately not `dns`: a DNS lookup makes signup as
            // slow and as unreliable as the resolver, and rejects legitimate
            // users on a transient failure. Deliverability is proven by the
            // verification email we already require.
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:mysql.users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'wants_to_teach' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'locale' => ['sometimes', 'string', Rule::in(config('orbito.locales.supported'))],
            // A stateful first-party request (SPA origin) gets a cookie session.
            // Any other client MUST name its device so we can issue — and later
            // revoke — a token for it. Silently returning neither would leave the
            // caller believing it is authenticated when it is not.
            'device_name' => [$this->hasSession() ? 'sometimes' : 'required', 'string', 'max:120'],
        ];
    }

    public function toData(): RegisterUserData
    {
        return new RegisterUserData(
            name: $this->string('name')->trim()->value(),
            email: mb_strtolower($this->string('email')->trim()->value()),
            password: $this->string('password')->value(),
            wantsToTeach: $this->boolean('wants_to_teach'),
            timezone: $this->string('timezone', 'UTC')->value(),
            locale: $this->string('locale', (string) config('orbito.locales.default'))->value(),
        );
    }
}
