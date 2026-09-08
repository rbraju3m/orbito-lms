<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Engagement\Events\QuestionAsked;
use App\Domain\Notification\Actions\NotifyUsers;
use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Enums\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The one notification pointing the other way.
 *
 * A question nobody is told about is a question nobody answers, and an
 * unanswered Q&A tab is worse than none at all.
 *
 * Staff means the course's OWN staff — the owner and its co-instructors —
 * resolved from `course_instructors` rather than from permissions. Asking
 * "who holds discussion.moderate?" would return every instructor and every
 * moderator in the academy, because those keys are held globally; that is the
 * CourseScopedAccessTest trap, and here it would mail a stranger about a
 * course they have never opened.
 */
final class NotifyStaffOnQuestionAsked implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly NotifyUsers $notify) {}

    public function handle(QuestionAsked $event): void
    {
        $discussion = $event->discussion;

        if (! $discussion->status->isVisible()) {
            return;
        }

        $discussion->loadMissing('course.instructors');
        $course = $discussion->course;

        if ($course === null) {
            return;
        }

        $payload = new NotificationPayload(
            type: NotificationType::QuestionAsked,
            title: 'New question in '.$course->title,
            body: NotificationPayload::excerpt($discussion->title.' — '.$discussion->body),
            actionLabel: 'Answer the question',
            actionPath: "/learn/{$course->uuid}/discussions/{$discussion->uuid}",
            meta: [
                'course_id' => $course->uuid,
                'course_title' => $course->title,
                'discussion_id' => $discussion->uuid,
            ],
        );

        $this->notify->handle(
            [$course->owner_id, ...$course->instructors->pluck('user_id')->all()],
            $payload,
            // An instructor asking a question in their own course does not
            // need telling about it.
            except: $discussion->user_id,
        );
    }
}
