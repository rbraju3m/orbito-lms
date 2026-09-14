<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use App\Domain\Content\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes through the policy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(LeadStatus::class)],
        ];
    }

    /** Not `status()`: never name an accessor after something a Request might have (§ Phase 16). */
    public function leadStatus(): LeadStatus
    {
        return LeadStatus::from((string) $this->input('status'));
    }
}
