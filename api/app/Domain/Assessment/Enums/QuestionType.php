<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * The question catalogue.
 *
 * Answer payload shapes, by type — these are the only shapes the API accepts:
 *   single_choice / true_false / image_choice  {"option_id": 12}
 *   multiple_choice                            {"option_ids": [1, 3]}
 *   short_answer / long_answer                 {"text": "..."}
 *   fill_blank                                 {"blanks": ["a", "b"]}
 *   matching / image_matching                  {"pairs": {"<optionId>": "<matchKey>"}}
 *   ordering                                   {"option_ids": [3, 1, 2]}
 */
enum QuestionType: string
{
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case TrueFalse = 'true_false';
    case ShortAnswer = 'short_answer';
    case LongAnswer = 'long_answer';
    case FillBlank = 'fill_blank';
    case Matching = 'matching';
    case Ordering = 'ordering';
    case ImageChoice = 'image_choice';
    case ImageMatching = 'image_matching';

    public function label(): string
    {
        return match ($this) {
            self::SingleChoice => 'Single choice',
            self::MultipleChoice => 'Multiple choice',
            self::TrueFalse => 'True or false',
            self::ShortAnswer => 'Short answer',
            self::LongAnswer => 'Long answer',
            self::FillBlank => 'Fill in the blank',
            self::Matching => 'Matching',
            self::Ordering => 'Ordering',
            self::ImageChoice => 'Image choice',
            self::ImageMatching => 'Image matching',
        };
    }

    /** Whether options are authored for this type. */
    public function hasOptions(): bool
    {
        return match ($this) {
            self::ShortAnswer, self::LongAnswer, self::FillBlank => false,
            default => true,
        };
    }

    /**
     * Whether the server can grade this without a human.
     *
     * Short answer is conditional: it grades itself when accepted answers are
     * configured, and queues for review when they are not. Long answer always
     * needs a person — pretending otherwise would be worse than saying so.
     */
    public function isAutoGradable(): bool
    {
        return $this !== self::LongAnswer;
    }

    public function alwaysNeedsReview(): bool
    {
        return $this === self::LongAnswer;
    }

    /** Types where a partially correct answer earns partial credit. */
    public function supportsPartialCredit(): bool
    {
        return match ($this) {
            self::MultipleChoice, self::Matching, self::ImageMatching, self::FillBlank => true,
            default => false,
        };
    }
}
