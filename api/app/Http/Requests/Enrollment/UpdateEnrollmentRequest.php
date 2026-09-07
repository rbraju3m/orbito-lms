<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Domain\Enrollment\Enums\EnrollmentAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Takes an explicit `action`, not mutable columns.
 *
 * Letting a PATCH set `status` directly would put the legal-transition rules
 * in the client's hands; naming the transition keeps them in
 * `ChangeEnrollmentStatus`, which is the Phase 4 lesson from ChangeCourseStatus.
 */
final class UpdateEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the enrollment.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(EnrollmentAction::class)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('action') !== EnrollmentAction::Extend->value) {
                return;
            }

            // "Extend" with nothing to extend to is almost certainly a client
            // bug; silently clearing the expiry would grant lifetime access.
            if (! $this->has('expires_at')) {
                $validator->errors()->add('expires_at', 'An extension needs a new expiry date, or null for no expiry.');
            }
        });
    }

    public function action(): EnrollmentAction
    {
        return EnrollmentAction::from((string) $this->validated('action'));
    }
}
