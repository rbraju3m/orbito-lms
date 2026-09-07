<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Enums\LatePolicy;
use App\Domain\Assessment\Enums\SubmissionStatus;
use App\Domain\Assessment\Events\AssignmentGraded;
use App\Domain\Assessment\Models\AssignmentSubmission;
use App\Domain\Identity\Models\User;
use App\Support\Html\RichTextSanitizer;

/**
 * Marks one submission.
 *
 * The grader supplies a raw score out of the assignment's total; the late
 * penalty is applied HERE, from what the row recorded at submission time.
 * Asking an instructor to do the arithmetic would make the penalty a matter of
 * whether they remembered, and would hide it from the learner.
 */
final class GradeSubmission
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    public function handle(
        AssignmentSubmission $submission,
        float $points,
        ?string $feedback,
        User $grader,
    ): AssignmentSubmission {
        $submission->loadMissing('assignment');
        $assignment = $submission->assignment;

        $total = (float) $assignment->total_points;
        // Clamp: a typo must not award more than the assignment is worth.
        $raw = max(0.0, min($points, $total));

        $penalty = $submission->is_late && $assignment->late_policy === LatePolicy::Penalise
            ? round($raw * ($assignment->late_penalty_percent / 100), 2)
            : 0.0;

        $final = max(0.0, round($raw - $penalty, 2));

        $passed = $assignment->passing_points === null
            ? null
            : $final >= (float) $assignment->passing_points;

        $submission->forceFill([
            'status' => SubmissionStatus::Graded,
            'points_raw' => $raw,
            'late_penalty_points' => $penalty,
            'points_earned' => $final,
            'passed' => $passed,
            'feedback' => $this->sanitizer->clean($feedback),
            'graded_by' => $grader->id,
            'graded_at' => now(),
        ])->save();

        AssignmentGraded::dispatch($submission, $passed ?? true);

        return $submission->refresh();
    }
}
