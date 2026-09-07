<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the course.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required_without:email', 'nullable', 'uuid'],
            'email' => ['required_without:user_id', 'nullable', 'email', 'max:255'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->student() === null) {
                $validator->errors()->add(
                    $this->filled('email') ? 'email' : 'user_id',
                    'No account matches that student.',
                );
            }
        });
    }

    /**
     * Resolved once here so the controller does not repeat the lookup — and so
     * "does this person exist?" is answered in exactly one place.
     */
    public function student(): ?User
    {
        return once(fn (): ?User => $this->filled('user_id')
            ? User::where('uuid', $this->string('user_id')->value())->first()
            : User::where('email', mb_strtolower(trim($this->string('email')->value())))->first());
    }
}
