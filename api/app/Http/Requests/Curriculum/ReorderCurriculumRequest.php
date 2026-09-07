<?php

declare(strict_types=1);

namespace App\Http\Requests\Curriculum;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The whole tree, in display order. Partial moves are deliberately not
 * accepted — see ReorderCurriculum.
 */
final class ReorderCurriculumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.id' => ['required', 'integer'],
            'sections.*.item_ids' => ['present', 'array'],
            'sections.*.item_ids.*' => ['integer'],
        ];
    }

    /** @return list<array{id: int, item_ids: list<int>}> */
    public function tree(): array
    {
        /** @var list<array{id: int, item_ids: list<int>}> */
        return array_map(
            fn (array $section): array => [
                'id' => (int) $section['id'],
                'item_ids' => array_map('intval', $section['item_ids']),
            ],
            $this->input('sections'),
        );
    }
}
