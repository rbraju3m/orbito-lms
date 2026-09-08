<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Engagement\Events\AnnouncementPublished;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Notification\Actions\NotifyUsers;
use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Enums\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The fan-out an announcement exists for.
 *
 * QUEUED, and not negotiably so: a course with five thousand learners turns
 * "publish" into five thousand inbox rows and five thousand emails, and the
 * instructor who pressed the button must not sit through it.
 *
 * The `notify` flag is honoured HERE rather than at publish time, because it
 * is a statement about delivery, not about publication — an announcement with
 * it off is still published, still visible in the course, just not pushed.
 */
final class NotifyOnAnnouncementPublished implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly NotifyUsers $notify) {}

    public function handle(AnnouncementPublished $event): void
    {
        $announcement = $event->announcement;

        if (! $announcement->notify) {
            return;
        }

        $announcement->loadMissing('course');
        $course = $announcement->course;

        if ($course === null) {
            return;
        }

        $payload = new NotificationPayload(
            type: NotificationType::AnnouncementPublished,
            title: $announcement->title,
            body: NotificationPayload::excerpt($announcement->body),
            actionLabel: 'Read the announcement',
            actionPath: "/learn/{$course->uuid}/announcements",
            meta: [
                'course_id' => $course->uuid,
                'course_title' => $course->title,
                'announcement_id' => $announcement->uuid,
            ],
        );

        /*
         * Tenant ids, resolved to central accounts inside the Action (§16).
         * Suspended and revoked learners are not an audience — `active` is the
         * same scope the course itself reads, so who hears about a course and
         * who may open it cannot disagree.
         */
        $this->notify->handle(
            Enrollment::query()
                ->active()
                ->where('course_id', $course->id)
                ->pluck('user_id'),
            $payload,
            except: $announcement->author_id,
        );
    }
}
