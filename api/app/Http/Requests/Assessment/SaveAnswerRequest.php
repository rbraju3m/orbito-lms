<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The answer payload is validated by SHAPE here and by MEANING in the grader.
 * Shapes are documented on QuestionType.
 */
final class SaveAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes ownership of the attempt.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question_id' => ['required', 'string', 'exists:questions,uuid'],
            'answer' => ['present', 'array'],

            'answer.option_id' => ['sometimes', 'nullable', 'integer'],
            'answer.option_ids' => ['sometimes', 'array', 'max:50'],
            'answer.option_ids.*' => ['integer'],
            'answer.text' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'answer.blanks' => ['sometimes', 'array', 'max:50'],
            'answer.blanks.*' => ['nullable', 'string', 'max:500'],
            'answer.pairs' => ['sometimes', 'array', 'max:50'],
            'answer.pairs.*' => ['nullable', 'string', 'max:191'],
        ];
    }
}
