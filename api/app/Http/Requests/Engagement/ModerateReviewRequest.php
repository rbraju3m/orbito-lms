<?php

declare(strict_types=1);

namespace App\Http\Requests\Engagement;

use App\Domain\Engagement\Enums\ReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class ModerateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the review.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(ReviewStatus::class)],
        ];
    }

    public function status(): ReviewStatus
    {
        return ReviewStatus::from((string) $this->string('status'));
    }
}
