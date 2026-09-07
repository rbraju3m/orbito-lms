<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Queries;

use App\Domain\Assessment\Enums\QuestionType;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuizAttempt;
use Illuminate\Support\Collection;

/**
 * Reads for the quiz runner and the results screen.
 */
final class AttemptQuery
{
    /**
     * The questions of an in-progress attempt, in the frozen order, with the
     * learner's saved answers. Options are shuffled per the quiz setting —
     * once, deterministically per attempt, so the order does not jump between
     * pages.
     *
     * @return Collection<int, Question>
     */
    public function questionsFor(QuizAttempt $attempt): Collection
    {
        // options.media too: the resource renders an image per option for
        // the image types, and lazy loading is forbidden.
        $attempt->loadMissing(['quiz.questions.options.media', 'quiz.questions.media']);

        $byId = $attempt->quiz->questions->keyBy('id');

        $questions = collect($attempt->question_order ?? [])
            ->map(fn (int $id) => $byId->get($id))
            ->filter()
            ->values();

        if ($attempt->quiz->shuffle_answers) {
            foreach ($questions as $question) {
                $question->setRelation(
                    'options',
                    // A DETERMINISTIC shuffle: ordering by a hash of
                    // (attempt, option) gives the same arrangement on every
                    // reload of the same attempt, and a different one per
                    // learner. Collection::shuffle() is random each call, so
                    // the options would jump around between pages.
                    $question->options
                        ->sortBy(fn ($option) => md5($attempt->uuid.':'.$option->id))
                        ->values(),
                );
            }
        }

        return $questions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function savedAnswers(QuizAttempt $attempt): array
    {
        $attempt->loadMissing('answers');

        return $attempt->answers
            ->mapWithKeys(fn ($answer) => [$answer->question_id => $answer->answer ?? []])
            ->all();
    }

    /**
     * The results breakdown. Correct answers are included ONLY when the quiz's
     * reveal policy allows it.
     *
     * @return list<array<string, mixed>>
     */
    public function review(QuizAttempt $attempt, bool $revealAnswers): array
    {
        $attempt->loadMissing(['quiz.questions.options', 'answers']);

        $byId = $attempt->quiz->questions->keyBy('id');
        $answers = $attempt->answers->keyBy('question_id');

        return collect($attempt->question_order ?? [])
            ->map(function (int $questionId) use ($byId, $answers, $revealAnswers): ?array {
                $question = $byId->get($questionId);

                if ($question === null) {
                    return null;
                }

                $given = $answers->get($questionId);

                $row = [
                    'question_id' => $question->uuid,
                    'type' => $question->type->value,
                    'title' => $question->title,
                    'points_possible' => (float) ($given->points_possible ?? 0),
                    'points_earned' => (float) ($given->points_earned ?? 0),
                    'is_correct' => $given?->is_correct,
                    'awaiting_review' => $given === null ? false : $given->awaitsReview(),
                    'your_answer' => $given?->answer,
                    // Raw ids are meaningless on screen, and only the server
                    // holds the labels, so the readable form is built here.
                    'your_answer_label' => $this->labelAnswer($question, $given?->answer),
                    'feedback' => $given?->feedback,
                ];

                if ($revealAnswers) {
                    $row['explanation'] = $question->explanation;
                    $row['correct_answer'] = $this->correctAnswerFor($question);
                }

                return $row;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Renders a stored answer payload as labels the learner will recognise.
     * Returns null when nothing usable was answered.
     */
    private function labelAnswer(Question $question, mixed $answer): mixed
    {
        if (! is_array($answer) || $answer === []) {
            return null;
        }

        $labels = [];

        foreach ($question->options as $option) {
            $labels[(int) $option->id] = (string) $option->label;
        }

        return match ($question->type) {
            QuestionType::SingleChoice, QuestionType::TrueFalse, QuestionType::ImageChoice => $labels[$this->asInt($answer['option_id'] ?? null)] ?? null,
            QuestionType::MultipleChoice, QuestionType::Ordering => $this->labelList($labels, $answer['option_ids'] ?? null),
            QuestionType::ShortAnswer, QuestionType::LongAnswer => is_string($answer['text'] ?? null) ? $answer['text'] : null,
            QuestionType::FillBlank => is_array($answer['blanks'] ?? null) ? array_values($answer['blanks']) : null,
            QuestionType::Matching, QuestionType::ImageMatching => $this->labelPairs($labels, $answer['pairs'] ?? null),
        };
    }

    /**
     * @param  array<int, string>  $labels
     * @return list<string>|null
     */
    private function labelList(array $labels, mixed $ids): ?array
    {
        if (! is_array($ids)) {
            return null;
        }

        $rendered = [];

        foreach ($ids as $id) {
            $label = $labels[$this->asInt($id)] ?? null;

            if ($label !== null) {
                $rendered[] = $label;
            }
        }

        return $rendered;
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<string, string>|null
     */
    private function labelPairs(array $labels, mixed $pairs): ?array
    {
        if (! is_array($pairs)) {
            return null;
        }

        $rendered = [];

        foreach ($pairs as $optionId => $target) {
            if (! is_string($target)) {
                continue;
            }

            $rendered[$labels[$this->asInt($optionId)] ?? (string) $optionId] = $target;
        }

        return $rendered;
    }

    /** Answer payloads arrive as JSON, so an id may be an int or a numeric string. */
    private function asInt(mixed $value): int
    {
        return is_int($value) || is_string($value) ? (int) $value : 0;
    }

    private function correctAnswerFor(Question $question): mixed
    {
        return match ($question->type->value) {
            'single_choice', 'true_false', 'image_choice' => $question->options
                ->firstWhere('is_correct', true)?->label,
            'multiple_choice' => $question->options->where('is_correct', true)->pluck('label')->values(),
            'ordering' => $question->options->sortBy('position')->pluck('label')->values(),
            'matching', 'image_matching' => $question->options
                ->mapWithKeys(fn ($o) => [$o->label => $o->match_key])
                ->all(),
            'short_answer' => $question->settings['accepted'] ?? [],
            'fill_blank' => array_map(
                fn (array $blank) => $blank['accepted'][0] ?? null,
                (array) ($question->settings['blanks'] ?? []),
            ),
            default => null,
        };
    }
}
