<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Live\Enums\WebinarStatus;
use App\Domain\Live\Events\WebinarStatusChanged;
use App\Domain\Live\Models\WebinarRegistration;
use App\Domain\Notification\Actions\NotifyUsers;
use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Enums\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;

/**
 * The event you were coming to is off.
 *
 * `ChangeWebinarStatus` deliberately leaves the registrations alone — a place
 * held is a record of who was coming, and the academy calling it off still
 * needs to know who to tell. This is the telling, and it is the only reason
 * `WebinarStatusChanged` carries the previous status: a cancellation is a
 * TRANSITION into `cancelled`, so calling an already-cancelled event off
 * again (the Action returns early) says nothing twice.
 *
 * TWO messages, not one, split on whether the place was bought. Money is a
 * different fact from a diary entry: somebody who paid needs to know a refund
 * is owed and who issues it, and a single payload cannot say that to half its
 * recipients — `NotifyUsers` sends one message to a set of people (§ Patterns
 * established in Phase 12: a notification is a frozen MESSAGE). The free
 * group is almost always the whole room; the paid group is empty unless the
 * webinar was sellable.
 *
 * There is NO action path. A cancelled webinar leaves the learner's list —
 * `WebinarController@index` shows published ones only — so a link would land
 * on a page that no longer mentions the thing it is about. A notification
 * with nothing to open is better than one that opens nothing (§ Patterns
 * established in Phase 9: `meta` carries what the caller can DO, and here
 * there is nothing to do but read it).
 *
 * Registrations with no `user_id` hear nothing, and cannot: delivery is to a
 * central account and a guest registration has only an email. Registration is
 * members-only today (§ Multi-tenancy — there is no anonymous surface), so
 * the case does not arise yet; the public path that creates it in P16 needs a
 * mail-only delivery, which is its own slice.
 */
final class NotifyOnWebinarCancelled implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly NotifyUsers $notify) {}

    public function handle(WebinarStatusChanged $event): void
    {
        if (! $event->became(WebinarStatus::Cancelled)) {
            return;
        }

        $webinar = $event->webinar;
        $webinar->loadMissing('session');

        /** @var Collection<int, WebinarRegistration> $registrations */
        $registrations = WebinarRegistration::query()
            ->where('webinar_id', $webinar->id)
            ->live()
            ->whereNotNull('user_id')
            ->get(['user_id', 'order_id']);

        if ($registrations->isEmpty()) {
            return;
        }

        $session = $webinar->session;

        /*
         * Rendered in the zone it was SCHEDULED in, not in the server's. The
         * instant and the zone are two facts (§ Patterns established in Phase
         * 15) and a wall-clock time printed from one while labelled with the
         * other is worse than no time at all: it reads as authoritative and
         * is six hours out.
         */
        $when = $session === null
            ? ''
            : ' It was scheduled for '
                .$session->starts_at->copy()->setTimezone($session->timezone)->format('j M Y, H:i')
                .' ('.$session->timezone.').';

        $meta = [
            'webinar_id' => $webinar->uuid,
            'webinar_title' => $webinar->title,
            'starts_at' => $session?->starts_at->toIso8601String(),
            'timezone' => $session?->timezone,
        ];

        /*
         * Split on whether the place was BOUGHT, not on the webinar's
         * `is_paid`: a free place given away at a paid event, or one held
         * before the event was ever priced, owes nobody a refund. The same
         * question `RevokeOrderAccess` asks of `order_id`.
         */
        $purchased = $registrations->whereNotNull('order_id')->pluck('user_id')->all();
        $free = $registrations->whereNull('order_id')->pluck('user_id')->all();

        if ($free !== []) {
            $this->notify->handle($free, $this->payload($webinar->title, $when, $meta, false), $event->actorId);
        }

        if ($purchased !== []) {
            $this->notify->handle($purchased, $this->payload($webinar->title, $when, $meta, true), $event->actorId);
        }
    }

    /** @param  array<string, mixed>  $meta */
    private function payload(string $title, string $when, array $meta, bool $purchased): NotificationPayload
    {
        /*
         * What the paid message does NOT say is that a refund is on its way.
         * Cancelling a webinar refunds nothing by itself — an academy issues
         * that from the order — so promising one here would be a message the
         * system cannot keep.
         */
        $body = $purchased
            ? 'You no longer hold a place at this event, and the place you paid for is refundable — ask the academy if a refund does not arrive.'
            : 'You no longer hold a place at this event.';

        return new NotificationPayload(
            type: NotificationType::WebinarCancelled,
            title: $title.' has been called off',
            body: $body.$when,
            meta: $meta + ['was_purchased' => $purchased],
        );
    }
}
