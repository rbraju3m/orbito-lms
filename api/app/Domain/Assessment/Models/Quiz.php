<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Enums\FeedbackMode;
use App\Domain\Assessment\Enums\GradingPolicy;
use App\Domain\Assessment\Enums\ShowAnswersAfter;
use App\Domain\Assessment\Enums\TimeExpiryPolicy;
use App\Domain\Curriculum\Models\CourseItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @property int $id
 * @property int|null $time_limit_seconds
 * @property TimeExpiryPolicy $time_expiry_policy
 * @property int|null $attempts_allowed
 * @property int $passing_score_percent
 * @property GradingPolicy $grading_policy
 * @property string $question_order
 * @property bool $shuffle_answers
 * @property int|null $questions_per_attempt
 * @property FeedbackMode $feedback_mode
 * @property ShowAnswersAfter $show_correct_answers_after
 * @property bool $negative_marking
 */
final class Quiz extends Model
{
    protected $table = 'quizzes';

    protected $fillable = [
        'description', 'instructions', 'time_limit_seconds', 'time_expiry_policy',
        'attempts_allowed', 'passing_score_percent', 'grading_policy',
        'question_order', 'shuffle_answers', 'questions_per_attempt', 'questions_per_page',
        'hide_question_numbers', 'feedback_mode', 'show_correct_answers_after',
        'negative_marking', 'allow_previous_button',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'time_expiry_policy' => TimeExpiryPolicy::class,
            'grading_policy' => GradingPolicy::class,
            'feedback_mode' => FeedbackMode::class,
            'show_correct_answers_after' => ShowAnswersAfter::class,
            'shuffle_answers' => 'boolean',
            'hide_question_numbers' => 'boolean',
            'negative_marking' => 'boolean',
            'allow_previous_button' => 'boolean',
        ];
    }

    /** @return MorphOne<CourseItem, $this> */
    public function item(): MorphOne
    {
        return $this->morphOne(CourseItem::class, 'itemable');
    }

    /** @return BelongsToMany<Question, $this> */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'quiz_questions')
            ->withPivot(['position', 'points_override'])
            ->orderBy('quiz_questions.position');
    }

    /** @return HasMany<QuizAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function hasTimeLimit(): bool
    {
        return $this->time_limit_seconds !== null && $this->time_limit_seconds > 0;
    }

    /** Points a question is worth in THIS quiz, honouring any override. */
    public function pointsFor(Question $question): float
    {
        $override = $question->pivot->points_override ?? null;

        return (float) ($override ?? $question->points);
    }
}
