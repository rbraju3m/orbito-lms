<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Enums\QuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $attempt_id
 * @property int $question_id
 * @property QuestionType $question_type
 * @property array<string, mixed>|null $answer
 * @property string $points_possible
 * @property string $points_earned
 * @property bool|null $is_correct
 */
final class QuizAttemptAnswer extends Model
{
    protected $fillable = [
        'attempt_id', 'question_id', 'question_type', 'answer',
        'points_possible', 'points_earned', 'is_correct', 'feedback', 'graded_by', 'graded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'question_type' => QuestionType::class,
            'answer' => 'array',
            'is_correct' => 'boolean',
            'points_possible' => 'decimal:2',
            'points_earned' => 'decimal:2',
            'graded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<QuizAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'attempt_id');
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function awaitsReview(): bool
    {
        return $this->is_correct === null;
    }
}
