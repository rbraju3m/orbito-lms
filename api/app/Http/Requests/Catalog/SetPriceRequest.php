<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One price, in one currency. Used by both the course and the bundle
 * endpoints — the rules about money are identical, and `SetProductPrice`
 * enforces the ones that need to look at each other.
 */
final class SetPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'size:3', Rule::in($this->supportedCurrencies())],
            // Minor units, integer, never a float (ADR-04). The cap is a
            // typo guard: a price above it is somebody adding two zeroes.
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000'],

            'sale_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'lt:amount_minor'],
            'sale_starts_at' => ['sometimes', 'nullable', 'date'],
            'sale_ends_at' => ['sometimes', 'nullable', 'date', 'after:sale_starts_at'],
        ];
    }

    /** @return list<string> */
    private function supportedCurrencies(): array
    {
        /** @var list<string> $supported */
        $supported = config('orbito.currency.supported', []);

        return array_map(strtoupper(...), $supported);
    }
}
