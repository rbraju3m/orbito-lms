<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use App\Domain\Assessment\Models\QuizAttempt;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once an attempt has a final score — immediately for a fully
 * auto-graded quiz, or after a human finishes the open questions.
 */
final class QuizAttemptGraded
{
    use Dispatchable;

    public function __construct(
        public readonly QuizAttempt $attempt,
        public readonly bool $passed,
    ) {}
}
