<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Domain\Identity\Enums\InstructorStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewInstructorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the InstructorProfile.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(InstructorStatus::class)->except(InstructorStatus::Pending)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function decision(): InstructorStatus
    {
        return InstructorStatus::from($this->string('decision')->value());
    }
}
