<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Domain\Enrollment\Actions\BulkEnrollStudents;
use Illuminate\Foundation\Http\FormRequest;

final class BulkEnrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the course.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Bounded, because this runs inside the request. Anything larger
            // belongs in a queued import, not a longer timeout.
            'emails' => ['required', 'array', 'min:1', 'max:'.BulkEnrollStudents::MAX_ROWS],
            'emails.*' => ['required', 'email', 'max:255'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'emails.max' => 'Enrol at most :max students at a time.',
        ];
    }
}
