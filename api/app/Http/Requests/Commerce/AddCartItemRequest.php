<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

final class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Any authenticated learner may fill their own basket.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * The public id, not the numeric one. Sequential ids in a request
             * body invite enumeration, and every other endpoint in the API
             * speaks uuid — `exists` is on the tenant connection, so a product
             * from another academy cannot resolve here at all (§16).
             */
            'product_id' => ['required', 'uuid', 'exists:products,uuid'],
        ];
    }
}
