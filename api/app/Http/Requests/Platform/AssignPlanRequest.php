<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

final class AssignPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The route is behind the super_admin middleware.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Central tables, named explicitly: the default connection inside
            // a request is an academy's schema.
            'plan' => ['required', 'string', 'exists:mysql.plans,slug'],
            'period_ends_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
