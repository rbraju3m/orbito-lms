<?php

declare(strict_types=1);

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDiscussionReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the discussion.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
            // Membership of THIS thread is checked in the action.
            'parent_id' => ['sometimes', 'nullable', 'uuid', 'exists:discussion_replies,uuid'],
        ];
    }
}
