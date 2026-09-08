<?php

declare(strict_types=1);

namespace App\Http\Requests\Engagement;

use App\Domain\Engagement\Enums\DiscussionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class StoreDiscussionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the course.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'type' => ['sometimes', new Enum(DiscussionType::class)],
            /*
             * The item's UUID, not its numeric id. Membership of THIS course
             * is checked in the action — `exists` proves the row is in the
             * academy, not that it belongs to this course.
             */
            'item_id' => ['sometimes', 'nullable', 'uuid', 'exists:course_items,uuid'],
        ];
    }

    public function type(): DiscussionType
    {
        return DiscussionType::tryFrom((string) $this->string('type')) ?? DiscussionType::Question;
    }
}
