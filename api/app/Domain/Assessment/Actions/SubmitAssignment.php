<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Enums\SubmissionStatus;
use App\Domain\Assessment\Events\AssignmentSubmitted;
use App\Domain\Assessment\Exceptions\SubmissionRejected;
use App\Domain\Assessment\Models\Assignment;
use App\Domain\Assessment\Models\AssignmentSubmission;
use App\Domain\Assessment\Models\AssignmentSubmissionFile;
use App\Domain\Assessment\Support\SubmissionRules;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Media\Models\Media;
use App\Domain\Progress\Actions\TrackItemProgress;
use App\Support\Html\RichTextSanitizer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Hands work in.
 *
 * Everything the learner's browser asserted is re-decided here: whether they
 * have an attempt left, whether the work is late, and what the deadline was.
 * The client is only permitted to supply the content.
 */
final class SubmitAssignment
{
    public function __construct(
        private readonly TrackItemProgress $progress,
        private readonly RichTextSanitizer $sanitizer,
    ) {}

    /**
     * @param  EloquentCollection<int, Media>  $files  already checked for
     *                                                 ownership and rules
     */
    public function handle(
        CourseItem $item,
        Assignment $assignment,
        Enrollment $enrollment,
        ?string $body,
        EloquentCollection $files,
    ): AssignmentSubmission {
        $body = $this->sanitizer->clean($body);

        if (blank($body) && $files->isEmpty()) {
            throw SubmissionRejected::empty();
        }

        if (filled($body) && ! $assignment->allow_text) {
            throw SubmissionRejected::textNotAllowed();
        }

        if ($files->isNotEmpty() && ! $assignment->allow_files) {
            throw SubmissionRejected::filesNotAllowed();
        }

        $rules = new SubmissionRules($assignment, $this->historyFor($assignment, $enrollment));

        match ($rules->refusal()) {
            'no_attempts_left' => throw SubmissionRejected::noAttemptsLeft(
                (int) $assignment->max_attempts
            ),
            'past_due' => throw SubmissionRejected::pastDue(),
            default => null,
        };

        // Decided here and frozen onto the row: moving due_at afterwards must
        // not retroactively make somebody late, or un-late.
        $isLate = $assignment->isPastDue();

        $submission = DB::transaction(function () use (
            $item, $assignment, $enrollment, $body, $files, $rules, $isLate
        ): AssignmentSubmission {
            $submission = AssignmentSubmission::create([
                'assignment_id' => $assignment->id,
                'course_item_id' => $item->id,
                'course_id' => $item->course_id,
                'user_id' => $enrollment->user_id,
                'enrollment_id' => $enrollment->id,
                'attempt_number' => $rules->nextAttemptNumber(),
                'status' => SubmissionStatus::Submitted,
                'body' => $body,
                'submitted_at' => now(),
                'is_late' => $isLate,
            ]);

            foreach ($files as $media) {
                AssignmentSubmissionFile::create([
                    'submission_id' => $submission->id,
                    'media_id' => $media->id,
                    'original_name' => $media->original_name,
                    'size_bytes' => $media->size_bytes,
                ]);
            }

            return $submission;
        });

        // Handing work in is engaging with the item, the same as submitting a
        // quiz. Waiting for the mark would leave the learner's progress stuck
        // behind an instructor's inbox.
        $this->progress->complete($enrollment, $item);

        AssignmentSubmitted::dispatch($submission);

        return $submission->load('files.media');
    }

    /** @return EloquentCollection<int, AssignmentSubmission> */
    public function historyFor(Assignment $assignment, Enrollment $enrollment): EloquentCollection
    {
        return AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $enrollment->user_id)
            ->with('files.media')
            ->orderBy('attempt_number')
            ->get();
    }
}
