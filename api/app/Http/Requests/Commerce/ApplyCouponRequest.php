<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

final class ApplyCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Your own basket; there is no other to address.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:64']];
    }

    public function code(): string
    {
        return (string) $this->string('code');
    }
}
