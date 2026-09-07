<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

use App\Domain\Assessment\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the course.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(QuestionType::class)],
            'title' => ['required', 'string', 'max:2000'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'explanation' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'points' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'negative_points' => ['sometimes', 'numeric', 'min:0', 'max:1000'],

            'settings' => ['sometimes', 'array'],
            'settings.accepted' => ['sometimes', 'array', 'max:50'],
            'settings.accepted.*' => ['string', 'max:500'],
            'settings.case_sensitive' => ['sometimes', 'boolean'],
            'settings.blanks' => ['sometimes', 'array', 'max:50'],
            'settings.blanks.*.accepted' => ['required', 'array', 'min:1', 'max:20'],
            'settings.blanks.*.accepted.*' => ['string', 'max:500'],

            'options' => ['sometimes', 'array', 'max:50'],
            'options.*.label' => ['required', 'string', 'max:2000'],
            'options.*.is_correct' => ['sometimes', 'boolean'],
            'options.*.match_key' => ['sometimes', 'nullable', 'string', 'max:191'],
            'options.*.position' => ['sometimes', 'integer', 'min:0'],
            'options.*.media_id' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = QuestionType::tryFrom((string) $this->input('type'));

            if ($type === null) {
                return;
            }

            /** @var list<array<string, mixed>> $options */
            $options = $this->input('options', []);

            // A question that cannot be answered correctly is worse than a
            // validation error — catch it at authoring time.
            if ($type->hasOptions() && count($options) < 2) {
                $validator->errors()->add('options', 'Add at least two options.');
            }

            $correct = collect($options)->filter(fn ($o) => (bool) ($o['is_correct'] ?? false))->count();

            if (in_array($type, [
                QuestionType::SingleChoice,
                QuestionType::TrueFalse,
                QuestionType::ImageChoice,
            ], true) && $correct !== 1) {
                $validator->errors()->add('options', 'Mark exactly one option correct.');
            }

            if ($type === QuestionType::MultipleChoice && $correct < 1) {
                $validator->errors()->add('options', 'Mark at least one option correct.');
            }

            if (in_array($type, [QuestionType::Matching, QuestionType::ImageMatching], true)) {
                $missing = collect($options)->filter(fn ($o) => blank($o['match_key'] ?? null))->count();

                if ($missing > 0) {
                    $validator->errors()->add('options', 'Every option needs something to match with.');
                }
            }

            if ($type === QuestionType::FillBlank && $this->input('settings.blanks', []) === []) {
                $validator->errors()->add('settings.blanks', 'Define the accepted answers for each blank.');
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function options(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->input('options', []);
    }
}
