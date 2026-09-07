<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use App\Domain\Assessment\Models\AssignmentSubmission;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A submission now has a final mark. Deliberately the same shape as
 * QuizAttemptGraded so a gradebook listener treats both alike.
 */
final class AssignmentGraded
{
    use Dispatchable;

    public function __construct(
        public readonly AssignmentSubmission $submission,
        public readonly bool $passed,
    ) {}
}
