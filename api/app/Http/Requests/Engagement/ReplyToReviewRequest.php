<?php

declare(strict_types=1);

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

final class ReplyToReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the review.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Nullable so the same endpoint removes a reply. A separate DELETE
            // for one nullable column is a route nobody would remember.
            'reply' => ['present', 'nullable', 'string', 'max:2000'],
        ];
    }
}
