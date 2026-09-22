<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Platform\Enums\RegistrationMode;
use App\Domain\Platform\Models\Tenant;
use App\Http\Requests\Concerns\ValidatesOwnedMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateAcademyRequest extends FormRequest
{
    use ValidatesOwnedMedia;

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
            // Null takes the logo down.
            'logo_media_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * An id that merely exists is not authorized (§ Patterns established in
     * Phase 4): the logo must be a file the caller uploaded as a logo.
     *
     * Except the logo already there. A colleague may have uploaded it, and
     * re-sending what is stored must not be refused for it — ownership is
     * checked on what is NEW, the page builder's rule.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $id = $this->input('logo_media_id');
            $current = Tenant::find($this->user()?->tenant_id)?->logoMediaId();

            if (is_numeric($id) && (int) $id !== $current) {
                $this->assertOwnedMedia($validator, 'logo_media_id', MediaCollection::AcademyLogo);
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'registration_mode.Illuminate\Validation\Rules\Enum' => 'Invitations are not available yet. Choose open or closed.',
        ];
    }
}
