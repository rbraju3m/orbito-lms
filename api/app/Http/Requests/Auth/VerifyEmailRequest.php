<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'min:1'],
            'hash' => ['required', 'string', 'size:40'],
            // Carried through from the emailed link and re-checked by the
            // `signed` middleware; validated here so a missing one is a 422,
            // not a confusing 403.
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string'],
        ];
    }
}
