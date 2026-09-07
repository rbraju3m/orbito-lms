<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Grading;

use App\Domain\Assessment\Enums\QuestionType;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuestionOption;

/**
 * Grades one answer against one question. Entirely server-side (ADR-06) — the
 * correct answers never leave this process during an attempt.
 *
 * One method per type rather than a class per type: the logic is small, and
 * keeping it together makes the partial-credit rules comparable at a glance.
 */
final class QuestionGrader
{
    /**
     * @param  array<string, mixed>|null  $answer
     */
    public function grade(Question $question, ?array $answer, float $points, bool $negativeMarking): GradeResult
    {
        // Checked BEFORE the manual-grading branch: a blank essay has nothing
        // for a person to read, and queueing it would leave the learner's whole
        // result pending on an instructor clicking through empty answers.
        if ($answer === null || $answer === []) {
            // Unanswered is not wrong-answered: no penalty for leaving it blank.
            return GradeResult::incorrect();
        }

        if ($question->needsManualGrading()) {
            return GradeResult::needsReview();
        }

        $penalty = $negativeMarking ? (float) $question->negative_points : 0.0;
        $question->loadMissing('options');

        return match ($question->type) {
            QuestionType::SingleChoice,
            QuestionType::TrueFalse,
            QuestionType::ImageChoice => $this->gradeSingleChoice($question, $answer, $points, $penalty),

            QuestionType::MultipleChoice => $this->gradeMultipleChoice($question, $answer, $points, $penalty),

            QuestionType::ShortAnswer => $this->gradeShortAnswer($question, $answer, $points, $penalty),

            QuestionType::FillBlank => $this->gradeFillBlank($question, $answer, $points),

            QuestionType::Matching,
            QuestionType::ImageMatching => $this->gradeMatching($question, $answer, $points),

            QuestionType::Ordering => $this->gradeOrdering($question, $answer, $points, $penalty),

            QuestionType::LongAnswer => GradeResult::needsReview(),
        };
    }

    /** @param  array<string, mixed>  $answer */
    private function gradeSingleChoice(Question $q, array $answer, float $points, float $penalty): GradeResult
    {
        $chosen = $answer['option_id'] ?? null;

        $correct = $q->options->firstWhere('is_correct', true);

        return $correct !== null && (int) $chosen === $correct->id
            ? GradeResult::correct($points)
            : GradeResult::incorrect($penalty);
    }

    /**
     * Partial credit, penalised for wrong picks: selecting everything must not
     * score full marks.
     *
     * @param  array<string, mixed>  $answer
     */
    private function gradeMultipleChoice(Question $q, array $answer, float $points, float $penalty): GradeResult
    {
        /** @var list<int> $chosen */
        $chosen = array_map('intval', (array) ($answer['option_ids'] ?? []));
        $chosen = array_values(array_unique($chosen));

        $correctIds = $q->options->where('is_correct', true)->pluck('id')->all();
        $wrongIds = $q->options->where('is_correct', false)->pluck('id')->all();

        if ($correctIds === []) {
            return GradeResult::incorrect($penalty);
        }

        $hits = count(array_intersect($chosen, $correctIds));
        $misses = count(array_intersect($chosen, $wrongIds));

        $score = ($hits - $misses) / count($correctIds);

        if ($score <= 0) {
            return GradeResult::incorrect($penalty);
        }

        return GradeResult::partial($score * $points, $points);
    }

    /** @param  array<string, mixed>  $answer */
    private function gradeShortAnswer(Question $q, array $answer, float $points, float $penalty): GradeResult
    {
        /** @var list<string> $accepted */
        $accepted = (array) ($q->settings['accepted'] ?? []);
        $caseSensitive = (bool) ($q->settings['case_sensitive'] ?? false);

        $given = $this->normalise((string) ($answer['text'] ?? ''), $caseSensitive);

        foreach ($accepted as $candidate) {
            if ($this->normalise((string) $candidate, $caseSensitive) === $given) {
                return GradeResult::correct($points);
            }
        }

        return GradeResult::incorrect($penalty);
    }

    /**
     * Each blank is worth an equal share, so getting three of four right
     * earns three quarters.
     *
     * @param  array<string, mixed>  $answer
     */
    private function gradeFillBlank(Question $q, array $answer, float $points): GradeResult
    {
        /** @var list<array{accepted: list<string>}> $blanks */
        $blanks = $q->settings['blanks'] ?? [];
        $caseSensitive = (bool) ($q->settings['case_sensitive'] ?? false);

        if ($blanks === []) {
            return GradeResult::needsReview();
        }

        /** @var list<string> $given */
        $given = (array) ($answer['blanks'] ?? []);
        $hits = 0;

        foreach ($blanks as $index => $blank) {
            $value = $this->normalise((string) ($given[$index] ?? ''), $caseSensitive);

            foreach ($blank['accepted'] as $candidate) {
                if ($value !== '' && $this->normalise((string) $candidate, $caseSensitive) === $value) {
                    $hits++;
                    break;
                }
            }
        }

        return $hits === 0
            ? GradeResult::incorrect()
            : GradeResult::partial(($hits / count($blanks)) * $points, $points);
    }

    /** @param  array<string, mixed>  $answer */
    private function gradeMatching(Question $q, array $answer, float $points): GradeResult
    {
        /** @var array<array-key, mixed> $pairs */
        $pairs = (array) ($answer['pairs'] ?? []);
        $options = $q->options->filter(fn (QuestionOption $o) => $o->match_key !== null);

        if ($options->isEmpty()) {
            return GradeResult::needsReview();
        }

        $hits = 0;

        foreach ($options as $option) {
            if (($pairs[(string) $option->id] ?? null) === $option->match_key) {
                $hits++;
            }
        }

        return $hits === 0
            ? GradeResult::incorrect()
            : GradeResult::partial(($hits / $options->count()) * $points, $points);
    }

    /**
     * All-or-nothing: a sequence that is nearly right is still the wrong
     * sequence.
     *
     * @param  array<string, mixed>  $answer
     */
    private function gradeOrdering(Question $q, array $answer, float $points, float $penalty): GradeResult
    {
        $expected = $q->options->sortBy('position')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $given = array_map('intval', (array) ($answer['option_ids'] ?? []));

        return $given === $expected
            ? GradeResult::correct($points)
            : GradeResult::incorrect($penalty);
    }

    private function normalise(string $value, bool $caseSensitive): string
    {
        // Collapse whitespace so "  two   words " matches "two words".
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $caseSensitive ? $value : mb_strtolower($value);
    }
}
