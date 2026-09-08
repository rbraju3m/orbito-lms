<?php

declare(strict_types=1);

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

final class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The action enforces enrolment; the controller the rest.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // 1..5 and integer. A half-star average is fine to DISPLAY; a
            // half-star input would make the column's meaning ambiguous.
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['sometimes', 'nullable', 'string', 'max:180'],
            // Sanitised on write by the action; capped here so a single
            // review cannot be a denial-of-service on the course page.
            'body' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
