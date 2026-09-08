<?php

declare(strict_types=1);

namespace App\Http\Requests\Certification;

use Illuminate\Foundation\Http\FormRequest;

final class RevokeCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the certificate.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Optional, and never shown on the public page — it is between the
            // academy and the holder, not the stranger checking a claim.
            'reason' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
