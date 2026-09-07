<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

use App\Domain\Assessment\Enums\FeedbackMode;
use App\Domain\Assessment\Enums\GradingPolicy;
use App\Domain\Assessment\Enums\ShowAnswersAfter;
use App\Domain\Assessment\Enums\TimeExpiryPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'time_limit_seconds' => ['sometimes', 'nullable', 'integer', 'min:30', 'max:86400'],
            'time_expiry_policy' => ['sometimes', Rule::enum(TimeExpiryPolicy::class)],
            'attempts_allowed' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'passing_score_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'grading_policy' => ['sometimes', Rule::enum(GradingPolicy::class)],
            'question_order' => ['sometimes', Rule::in(['sorted', 'random'])],
            'shuffle_answers' => ['sometimes', 'boolean'],
            'questions_per_attempt' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'questions_per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'hide_question_numbers' => ['sometimes', 'boolean'],
            'feedback_mode' => ['sometimes', Rule::enum(FeedbackMode::class)],
            'show_correct_answers_after' => ['sometimes', Rule::enum(ShowAnswersAfter::class)],
            'negative_marking' => ['sometimes', 'boolean'],
            'allow_previous_button' => ['sometimes', 'boolean'],
        ];
    }
}
