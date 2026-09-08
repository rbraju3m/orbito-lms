<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Assessment\Events\AssignmentGraded;
use App\Domain\Notification\Actions\NotifyUsers;
use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Enums\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A human read your work and gave it a mark.
 *
 * This is the notification with the most obvious reason to exist: grading is
 * the one thing in the product that happens on somebody else's schedule, and
 * without it a learner's only option is to keep checking.
 *
 * There is deliberately no equivalent for QuizAttemptGraded. An auto-graded
 * quiz is scored while the learner is still looking at the screen — telling
 * them about it is telling them what they just watched happen (see
 * NotificationType). The gap is real for a quiz with essay questions a human
 * marks later, and that is the case to add when the grading queue grows one.
 */
final class NotifyOnAssignmentGraded implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly NotifyUsers $notify) {}

    public function handle(AssignmentGraded $event): void
    {
        $submission = $event->submission;
        // The title and the player URL both live on the curriculum ITEM, not
        // on the assignment — `course_items` is the spine (§11).
        $submission->loadMissing(['course', 'item']);

        $course = $submission->course;
        $item = $submission->item;

        if ($course === null || $item === null) {
            return;
        }

        $payload = new NotificationPayload(
            type: NotificationType::AssignmentGraded,
            title: $item->title.' has been graded',
            body: $event->passed
                ? 'You passed. Open the submission to read the feedback.'
                : 'It has been marked. Open the submission to read the feedback.',
            actionLabel: 'See the result',
            actionPath: "/learn/{$course->uuid}/{$item->uuid}",
            meta: [
                'course_id' => $course->uuid,
                'course_title' => $course->title,
                'submission_id' => $submission->uuid,
                'passed' => $event->passed,
                // Absent is not zero (§14): an ungraded field would be a lie,
                // and this one is graded, so the number is real.
                'points_earned' => $submission->points_earned,
            ],
        );

        $this->notify->handle([$submission->user_id], $payload);
    }
}
