<?php

declare(strict_types=1);

namespace App\Http\Requests\Curriculum;

use App\Domain\Curriculum\Enums\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', 'exists:course_sections,id'],
            // Only types whose entity exists today. Offering a Quiz option that
            // 409s would be a worse experience than not offering it.
            'type' => ['required', Rule::in(array_map(
                fn (ItemType $type) => $type->value,
                ItemType::available(),
            ))],
            'title' => ['required', 'string', 'min:1', 'max:180'],
        ];
    }

    public function type(): ItemType
    {
        return ItemType::from($this->string('type')->value());
    }
}
