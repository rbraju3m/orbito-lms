<?php

declare(strict_types=1);

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the course.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:200'],
            'body' => [$required, 'string', 'max:20000'],
            /*
             * Publishing is a separate endpoint, not a field here. Saving a
             * draft and sending it to a thousand people are different acts and
             * should not be one careless boolean apart.
             */
            'notify' => ['sometimes', 'boolean'],
        ];
    }
}
