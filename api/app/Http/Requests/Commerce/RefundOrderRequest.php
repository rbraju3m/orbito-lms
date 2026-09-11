<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use App\Domain\Commerce\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * How much, how, and why. Whether the amount is still available on the order
 * is not a validation rule — it depends on other refunds, and is answered
 * under a lock (`ClaimRefund`, 422 `refund_rejected` with `refundable_minor`).
 */
final class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes through the policy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(RefundMethod::class)],
            'reason' => ['nullable', 'string', 'max:500'],
            // Takes effect only if this refund empties the order.
            'revoke_access' => ['sometimes', 'boolean'],
        ];
    }

    /** Not `method()` — that is the HTTP verb, and overriding it breaks the request. */
    public function refundMethod(): RefundMethod
    {
        return RefundMethod::from((string) $this->string('method'));
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? (string) $this->string('reason') : null;
    }

    /** Defaults to true: a full refund takes back what it paid for, unless told otherwise. */
    public function revokeAccess(): bool
    {
        return $this->has('revoke_access') ? $this->boolean('revoke_access') : true;
    }
}
