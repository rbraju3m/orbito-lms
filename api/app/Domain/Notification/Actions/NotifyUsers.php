<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Notifications\DomainNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Deliver one payload to a set of people.
 *
 * Every listener funnels through here so the three things that are easy to
 * get wrong are got wrong in one place, or not at all:
 *
 * 1. The ids are TENANT ids (an enrollment, a discussion, a course
 *    instructor) but the accounts are CENTRAL. `whereIn` on the central
 *    connection is the only way across — a `whereHas` from either side
 *    compiles to one statement spanning two databases and cannot work (§16).
 * 2. Nobody is notified about their own action. The actor is excluded here
 *    rather than by each caller remembering to.
 * 3. A course announcement can address thousands of people, so recipients are
 *    chunked and never loaded in one go.
 *
 * Preferences are NOT consulted here. `DomainNotification::via()` is the one
 * enforcement point, so a delivery that skips this Action still obeys them.
 *
 * @see DomainNotification
 */
final class NotifyUsers
{
    /** Recipients loaded per round trip. Announcements are the reason. */
    private const CHUNK = 500;

    /**
     * @param  iterable<int>  $userIds
     * @param  int|null  $except  The actor. They watched it happen.
     */
    public function handle(iterable $userIds, NotificationPayload $payload, ?int $except = null): int
    {
        $ids = collect($userIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === $except || $id <= 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        $sent = 0;

        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            $recipients = User::query()
                ->whereIn('id', $chunk->all())
                // A closed account is not an audience. Deleted users are gone
                // by the model's own soft-delete scope; this catches the rest.
                ->whereNull('deleted_at')
                ->get();

            if ($recipients->isEmpty()) {
                continue;
            }

            Notification::send($recipients, new DomainNotification($payload));

            $sent += $recipients->count();
        }

        return $sent;
    }
}
