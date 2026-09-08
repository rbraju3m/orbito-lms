<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Gamification\Events\BadgeAwarded;
use App\Domain\Notification\Actions\NotifyUsers;
use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Enums\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A badge nobody is told about is a badge nobody earned.
 *
 * This is the one notification type where the RECIPIENT DID CAUSE IT — they
 * completed the lesson that tipped them over — and it is here anyway, which
 * is the exception that proves the rule in NotificationType. Nobody watches
 * a threshold they cannot see; the crossing happens in a queued job minutes
 * later, and the badge is the system telling them something they had no way
 * to know.
 */
final class NotifyOnBadgeAwarded implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly NotifyUsers $notify) {}

    public function handle(BadgeAwarded $event): void
    {
        $badge = $event->badge;

        $this->notify->handle([$event->userId], new NotificationPayload(
            type: NotificationType::BadgeAwarded,
            title: 'You earned “'.$badge->name.'”',
            body: $badge->description ?? 'A new badge is on your profile.',
            actionLabel: 'See your badges',
            actionPath: '/achievements',
            meta: [
                'badge_key' => $badge->key,
                'badge_name' => $badge->name,
                'tier' => $badge->tier->value,
            ],
        ));
    }
}
