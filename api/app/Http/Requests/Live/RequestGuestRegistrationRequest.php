<?php

declare(strict_types=1);

namespace App\Http\Requests\Live;

use App\Support\Http\FormTokenVerdict;
use App\Support\Http\PublicFormToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * A stranger asking for a place at a free webinar (docs/GUEST_REGISTRATION.md).
 *
 * The same form-token and honeypot layers as the lead form: a filled honeypot
 * or a form posted too fast passes validation and is then DISCARDED, answered
 * exactly like a real request (`isTrap()`).
 */
final class RequestGuestRegistrationRequest extends FormRequest
{
    private ?FormTokenVerdict $verdict = null;

    public function authorize(): bool
    {
        return true; // Anonymous by design — see GuestRegistrationController.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `rfc`, not `dns`: no lookup on somebody else's resolver per POST.
            // 191 is `webinar_registrations.email`.
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'name' => ['nullable', 'string', 'max:160'],
            'form_token' => ['required', 'string', 'max:2000'],
            // The honeypot.
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->verdict = app(PublicFormToken::class)->check(
                (string) $this->input('form_token'),
                (string) $this->route('academy'),
                now(),
            );

            if ($this->verdict === FormTokenVerdict::Expired || $this->verdict === FormTokenVerdict::Invalid) {
                $validator->errors()->add('form_token', 'This form has expired. Reload the page and try again.');
            }
        }];
    }

    public function isTrap(): bool
    {
        return $this->filled('website') || $this->verdict === FormTokenVerdict::TooFast;
    }

    public function email(): string
    {
        return Str::lower(trim((string) $this->input('email')));
    }

    public function guestName(): ?string
    {
        $name = trim((string) $this->input('name', ''));

        return $name === '' ? null : $name;
    }
}
