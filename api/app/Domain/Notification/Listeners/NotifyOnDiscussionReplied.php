<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Engagement\Events\DiscussionReplied;
use App\Domain\Engagement\Models\DiscussionReply;
use App\Domain\Notification\Actions\NotifyUsers;
use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Enums\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Somebody answered you.
 *
 * The audience is the two people who were actually addressed — whoever asked
 * the question, and whoever wrote the reply this one hangs under. NOT everyone
 * in the thread: a busy question would then mail a dozen bystanders every time
 * anybody added a line, and the fastest way to make people mute a course is to
 * copy them on a conversation they are not having.
 *
 * DiscussionReplied fires for deletions too, with a null reply id. A deletion
 * has nobody to tell.
 */
final class NotifyOnDiscussionReplied implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly NotifyUsers $notify) {}

    public function handle(DiscussionReplied $event): void
    {
        if ($event->replyId === null) {
            return;
        }

        $reply = DiscussionReply::query()
            ->with(['discussion.course', 'parent'])
            ->find($event->replyId);

        if ($reply === null || ! $reply->isVisible()) {
            return;
        }

        $discussion = $reply->discussion;
        $course = $discussion?->course;

        // A hidden thread does not push itself into anybody's inbox.
        if ($discussion === null || $course === null || ! $discussion->status->isVisible()) {
            return;
        }

        $payload = new NotificationPayload(
            type: NotificationType::DiscussionReplied,
            title: 'New reply: '.$discussion->title,
            body: NotificationPayload::excerpt($reply->body),
            actionLabel: 'Read the reply',
            actionPath: "/learn/{$course->uuid}/discussions/{$discussion->uuid}",
            meta: [
                'course_id' => $course->uuid,
                'course_title' => $course->title,
                'discussion_id' => $discussion->uuid,
                'reply_id' => $reply->uuid,
            ],
        );

        $this->notify->handle(
            [$discussion->user_id, $reply->parent?->user_id],
            $payload,
            except: $reply->user_id,
        );
    }
}
