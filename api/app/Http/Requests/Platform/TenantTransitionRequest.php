<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Domain\Platform\Enums\TenantAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Takes a named transition, not a status. The legal moves live in
 * ChangeTenantStatus, not in whatever the client happens to send.
 */
final class TenantTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The route is behind the super_admin middleware.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(TenantAction::class)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function action(): TenantAction
    {
        return TenantAction::from((string) $this->validated('action'));
    }
}
