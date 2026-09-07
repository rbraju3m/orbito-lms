<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Events\CourseStatusChanged;
use App\Domain\Catalog\Exceptions\CourseNotPublishable;
use App\Domain\Catalog\Exceptions\CourseTransitionRejected;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Support\PublishChecklist;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every lifecycle move goes through here — publish, submit, approve, reject,
 * archive, unpublish. One place owns the legal transitions and the timestamps,
 * so no controller can invent a state.
 */
final class ChangeCourseStatus
{
    public function __construct(private readonly PublishChecklist $checklist) {}

    public function handle(
        Course $course,
        CourseStatus $target,
        User $actor,
        ?string $note = null,
    ): Course {
        $from = $course->status;

        if ($from === $target) {
            return $course;
        }

        if (! $from->canTransitionTo($target)) {
            throw CourseTransitionRejected::illegal($from, $target);
        }

        // Publishing is the only transition with content requirements, and they
        // are the same rules the Studio checklist renders.
        if ($target === CourseStatus::Published) {
            $failures = $this->checklist->blockingFailures($course);

            if ($failures !== []) {
                throw new CourseNotPublishable($failures);
            }
        }

        DB::transaction(function () use ($course, $target, $note): void {
            $course->status = $target;
            $course->review_note = $note;

            match ($target) {
                // published_at is set once, on first publish: it is the
                // course's birthday, not the timestamp of the last edit.
                CourseStatus::Published => $course->published_at ??= now(),
                CourseStatus::InReview => $course->submitted_at = now(),
                CourseStatus::Archived => $course->archived_at = now(),
                CourseStatus::Draft => $course->submitted_at = null,
            };

            if ($target !== CourseStatus::Archived) {
                $course->archived_at = null;
            }

            $course->save();
        });

        CourseStatusChanged::dispatch($course, $from, $target, $actor->id);

        return $course->refresh();
    }
}
