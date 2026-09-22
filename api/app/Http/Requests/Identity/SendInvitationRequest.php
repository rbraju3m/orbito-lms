<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Domain\Identity\Enums\InvitationRole;
use App\Domain\Identity\Models\Invitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SendInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller asks InvitationPolicy.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Invitation::normaliseEmail($this->input('email'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * One account belongs to one academy (§ Multi-tenancy), so an
             * address with an account anywhere cannot be invited. Saying so
             * tells the inviter the address is registered on the platform; it
             * is told only to staff holding `invitation.manage`, and the
             * alternative is a link that fails in the invitee's hands.
             */
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'unique:mysql.users,email'],
            'role' => ['required', Rule::enum(InvitationRole::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'This address already has an account, so it cannot be invited.',
        ];
    }

    public function email(): string
    {
        return (string) $this->input('email');
    }

    public function invitationRole(): InvitationRole
    {
        return InvitationRole::from((string) $this->input('role'));
    }
}
