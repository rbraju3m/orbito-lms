<?php

declare(strict_types=1);

namespace Database\Factories\Assessment;

use App\Domain\Assessment\Enums\QuestionType;
use App\Domain\Assessment\Models\Question;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Question>
 */
final class QuestionFactory extends Factory
{
    protected $model = Question::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'bank_id' => null,
            'owner_id' => User::factory(),
            'type' => QuestionType::SingleChoice,
            'title' => 'Which line opens the poem?',
            'body' => null,
            'explanation' => 'Because it does.',
            'points' => 1,
            'negative_points' => 0,
            'media_id' => null,
            'settings' => null,
        ];
    }

    public function singleChoice(): static
    {
        return $this->state(fn () => ['type' => QuestionType::SingleChoice])
            ->afterCreating(function (Question $q): void {
                $q->options()->createMany([
                    ['label' => 'Right', 'is_correct' => true, 'position' => 0],
                    ['label' => 'Wrong', 'is_correct' => false, 'position' => 1],
                    ['label' => 'Also wrong', 'is_correct' => false, 'position' => 2],
                ]);
            });
    }

    public function trueFalse(): static
    {
        return $this->state(fn () => ['type' => QuestionType::TrueFalse])
            ->afterCreating(function (Question $q): void {
                $q->options()->createMany([
                    ['label' => 'True', 'is_correct' => true, 'position' => 0],
                    ['label' => 'False', 'is_correct' => false, 'position' => 1],
                ]);
            });
    }

    public function multipleChoice(): static
    {
        return $this->state(fn () => ['type' => QuestionType::MultipleChoice, 'points' => 4])
            ->afterCreating(function (Question $q): void {
                $q->options()->createMany([
                    ['label' => 'A', 'is_correct' => true, 'position' => 0],
                    ['label' => 'B', 'is_correct' => true, 'position' => 1],
                    ['label' => 'C', 'is_correct' => false, 'position' => 2],
                    ['label' => 'D', 'is_correct' => false, 'position' => 3],
                ]);
            });
    }

    public function ordering(): static
    {
        return $this->state(fn () => ['type' => QuestionType::Ordering])
            ->afterCreating(function (Question $q): void {
                $q->options()->createMany([
                    ['label' => 'First', 'position' => 0],
                    ['label' => 'Second', 'position' => 1],
                    ['label' => 'Third', 'position' => 2],
                ]);
            });
    }

    public function matching(): static
    {
        return $this->state(fn () => ['type' => QuestionType::Matching, 'points' => 3])
            ->afterCreating(function (Question $q): void {
                $q->options()->createMany([
                    ['label' => 'Tagore', 'match_key' => 'Gitanjali', 'position' => 0],
                    ['label' => 'Nazrul', 'match_key' => 'Bidrohi', 'position' => 1],
                    ['label' => 'Jibanananda', 'match_key' => 'Banalata Sen', 'position' => 2],
                ]);
            });
    }

    public function shortAnswer(bool $withAccepted = true): static
    {
        return $this->state(fn () => [
            'type' => QuestionType::ShortAnswer,
            'settings' => $withAccepted ? ['accepted' => ['Tagore', 'Rabindranath Tagore']] : [],
        ]);
    }

    public function longAnswer(): static
    {
        return $this->state(fn () => ['type' => QuestionType::LongAnswer, 'points' => 5]);
    }

    public function fillBlank(): static
    {
        return $this->state(fn () => [
            'type' => QuestionType::FillBlank,
            'points' => 2,
            'settings' => [
                'blanks' => [
                    ['accepted' => ['Tagore']],
                    ['accepted' => ['Gitanjali']],
                ],
            ],
        ]);
    }

    public function imageChoice(): static
    {
        return $this->state(fn () => ['type' => QuestionType::ImageChoice])
            ->afterCreating(function (Question $q): void {
                $q->options()->createMany([
                    ['label' => 'Left image', 'is_correct' => true, 'position' => 0],
                    ['label' => 'Right image', 'is_correct' => false, 'position' => 1],
                ]);
            });
    }

    public function imageMatching(): static
    {
        return $this->state(fn () => ['type' => QuestionType::ImageMatching, 'points' => 2])
            ->afterCreating(function (Question $q): void {
                $q->options()->createMany([
                    ['label' => 'Portrait A', 'match_key' => 'Tagore', 'position' => 0],
                    ['label' => 'Portrait B', 'match_key' => 'Nazrul', 'position' => 1],
                ]);
            });
    }
}
