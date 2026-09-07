<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Identity\Models\User;
use App\Support\Html\RichTextSanitizer;
use Illuminate\Support\Facades\DB;

final class ManageQuizQuestions
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $options
     */
    public function create(Quiz $quiz, User $owner, array $data, array $options): Question
    {
        return DB::transaction(function () use ($quiz, $owner, $data, $options): Question {
            $question = Question::create([
                'bank_id' => $data['bank_id'] ?? null,
                'owner_id' => $owner->id,
                'type' => $data['type'],
                'title' => $data['title'],
                'body' => $this->sanitizer->clean($data['body'] ?? null),
                'explanation' => $this->sanitizer->clean($data['explanation'] ?? null),
                'points' => $data['points'] ?? 1,
                'negative_points' => $data['negative_points'] ?? 0,
                'settings' => $data['settings'] ?? null,
            ]);

            $this->syncOptions($question, $options);

            $quiz->questions()->attach($question->id, [
                'position' => (int) DB::table('quiz_questions')
                    ->where('quiz_id', $quiz->id)->max('position') + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $question;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $options
     */
    public function update(Question $question, array $data, ?array $options): Question
    {
        return DB::transaction(function () use ($question, $data, $options): Question {
            $question->fill(array_filter([
                'title' => $data['title'] ?? null,
                'points' => $data['points'] ?? null,
                'negative_points' => $data['negative_points'] ?? null,
            ], fn ($value) => $value !== null));

            foreach (['body', 'explanation'] as $field) {
                if (array_key_exists($field, $data)) {
                    $question->{$field} = $this->sanitizer->clean($data[$field]);
                }
            }

            if (array_key_exists('settings', $data)) {
                $question->settings = $data['settings'];
            }

            $question->save();

            if ($options !== null) {
                $this->syncOptions($question, $options);
            }

            return $question->refresh()->load('options');
        });
    }

    public function detach(Quiz $quiz, Question $question): void
    {
        $quiz->questions()->detach($question->id);
    }

    /**
     * Replaces the option set wholesale. Options carry the correct answers, so
     * a partial update would risk leaving a stale "correct" flag behind.
     *
     * @param  list<array<string, mixed>>  $options
     */
    private function syncOptions(Question $question, array $options): void
    {
        if (! $question->type->hasOptions()) {
            $question->options()->delete();

            return;
        }

        $question->options()->delete();

        foreach ($options as $index => $option) {
            $question->options()->create([
                'label' => $this->sanitizer->clean((string) ($option['label'] ?? '')) ?? '',
                'media_id' => $option['media_id'] ?? null,
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'match_key' => $option['match_key'] ?? null,
                'position' => (int) ($option['position'] ?? $index),
            ]);
        }
    }
}
