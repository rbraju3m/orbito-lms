<?php

declare(strict_types=1);

namespace App\Domain\Live\Listeners;

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Live\Events\AttendanceRecorded;
use App\Domain\Progress\Actions\TrackItemProgress;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Turning up completes the item.
 *
 * A live session is COMPLETABLE but not self-markable — the same rule as a
 * quiz or an assignment (§13): completion that is earned must not be a button.
 * The difference is what counts as earning it. There is no score and no
 * submission, so the evidence is attendance, and this is the one place that
 * turns the two into each other.
 *
 * Queued, and it silently does nothing when the session is not on the spine or
 * the attendee is not enrolled — a host joining their own class is not a
 * learner completing a lesson.
 */
final class CompleteItemOnAttendance implements ShouldQueue
{
    public function __construct(private readonly TrackItemProgress $progress) {}

    public function handle(AttendanceRecorded $event): void
    {
        $session = $event->attendance->session;

        if ($session === null || $session->course_id === null) {
            return;
        }

        $item = $session->item;

        if ($item === null) {
            // A session that is not on the curriculum spine — a cohort's
            // weekly call, say. Real attendance, nothing to complete.
            return;
        }

        $enrollment = Enrollment::query()
            ->where('course_id', $session->course_id)
            ->where('user_id', $event->attendance->user_id)
            ->first();

        if ($enrollment === null) {
            return;
        }

        $this->progress->complete($enrollment, $item);
    }
}
