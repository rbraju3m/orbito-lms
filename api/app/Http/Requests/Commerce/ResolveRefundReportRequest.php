<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

/** What the person did about it, for whoever reads the report next. Optional. */
final class ResolveRefundReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes through the policy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function note(): ?string
    {
        return $this->filled('note') ? (string) $this->string('note') : null;
    }
}
