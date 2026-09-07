<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Enums\SubmissionStatus;
use App\Domain\Assessment\Events\AssignmentReturned;
use App\Domain\Assessment\Models\AssignmentSubmission;
use App\Domain\Identity\Models\User;
use App\Support\Html\RichTextSanitizer;

/**
 * Hands work back without a mark: "this does not count, have another go."
 *
 * A returned submission does not consume an attempt (see `SubmissionRules`),
 * so this re-opens the assignment even for a learner who had used their last
 * one. Charging them for work the instructor chose not to grade would make the
 * gesture punitive.
 */
final class ReturnSubmission
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    public function handle(
        AssignmentSubmission $submission,
        ?string $feedback,
        User $grader,
    ): AssignmentSubmission {
        $submission->forceFill([
            'status' => SubmissionStatus::Returned,
            'points_raw' => null,
            'late_penalty_points' => 0,
            'points_earned' => null,
            'passed' => null,
            'feedback' => $this->sanitizer->clean($feedback),
            'graded_by' => $grader->id,
            'graded_at' => now(),
        ])->save();

        AssignmentReturned::dispatch($submission);

        return $submission->refresh();
    }
}
