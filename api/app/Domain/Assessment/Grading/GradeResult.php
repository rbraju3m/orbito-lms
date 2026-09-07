<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Grading;

/**
 * The outcome of grading one answer.
 *
 * `isCorrect === null` means "a human must decide" — distinct from `false`,
 * which means the server decided and the learner got it wrong.
 */
final readonly class GradeResult
{
    private function __construct(
        public float $pointsEarned,
        public ?bool $isCorrect,
    ) {}

    public static function correct(float $points): self
    {
        return new self($points, true);
    }

    public static function incorrect(float $penalty = 0.0): self
    {
        return new self(-abs($penalty), false);
    }

    /** Partial credit, floored at zero: a partly-right answer never costs marks. */
    public static function partial(float $earned, float $possible): self
    {
        $earned = max(0.0, min($earned, $possible));

        return new self($earned, $earned >= $possible && $possible > 0);
    }

    public static function needsReview(): self
    {
        return new self(0.0, null);
    }
}
