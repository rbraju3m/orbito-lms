<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Domain\Platform\Enums\RegistrationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAcademyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against AcademyPolicy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * `invite` is a legal enum case and NOT an accepted value: there
             * is no invitations table and no accept flow, so selecting it
             * would close registration while appearing to open a second door.
             * Refused here, at the edge, rather than half-honoured inside.
             */
            'registration_mode' => [
                'sometimes',
                Rule::enum(RegistrationMode::class)->except(RegistrationMode::Invite),
            ],
            'support_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'registration_mode.Illuminate\Validation\Rules\Enum' => 'Invitations are not available yet. Choose open or closed.',
        ];
    }
}
