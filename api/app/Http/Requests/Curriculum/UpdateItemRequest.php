<?php

declare(strict_types=1);

namespace App\Http\Requests\Curriculum;

use App\Domain\Curriculum\Models\CourseItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:1', 'max:180'],
            'is_preview' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'duration_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],

            // Drip parameters. All three are stored regardless of the course's
            // current drip_mode, so switching mode reinterprets what is
            // already there instead of discarding the author's work.
            'drip_available_at' => ['sometimes', 'nullable', 'date'],
            'drip_after_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'drip_after_item_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $afterId = $this->input('drip_after_item_id');

            if ($afterId === null) {
                return;
            }

            /** @var CourseItem $item */
            $item = $this->route('item');

            if ((int) $afterId === $item->id) {
                $validator->errors()->add('drip_after_item_id', 'An item cannot depend on itself.');

                return;
            }

            // `exists` is not authorization (Phase 4). A prerequisite item in
            // somebody else's course would leak its title through the lock
            // message and stall this course on content its author cannot see.
            $sameCourse = CourseItem::query()
                ->where('course_id', $item->course_id)
                ->whereKey($afterId)
                ->exists();

            if (! $sameCourse) {
                $validator->errors()->add('drip_after_item_id', 'That item is not part of this course.');
            }
        });
    }
}
