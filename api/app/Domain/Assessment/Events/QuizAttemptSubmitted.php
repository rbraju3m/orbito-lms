<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use App\Domain\Assessment\Models\QuizAttempt;
use Illuminate\Foundation\Events\Dispatchable;

final class QuizAttemptSubmitted
{
    use Dispatchable;

    public function __construct(public readonly QuizAttempt $attempt) {}
}
