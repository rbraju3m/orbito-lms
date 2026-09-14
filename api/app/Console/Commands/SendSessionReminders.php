<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\WebinarRegistration;
use App\Domain\Live\Notifications\GuestMail;
use App\Domain\Live\Queries\SessionAudience;
use App\Domain\Live\Support\GuestLinks;
use App\Domain\Live\Support\GuestToken;
use App\Domain\Notification\Actions\NotifyUsers;
use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Enums\NotificationType;
use App\Support\Console\RunsForEveryTenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Tells people about a class that is about to start.
 *
 * `reminder_sent_at` is a COLUMN rather than a queue-level guard, because the
 * scheduler may run on more than one host and "did we already?" has to be
 * answerable from the row. It is claimed BEFORE the notifications go out: a
 * crash halfway through under-notifies a few people, where the reverse would
 * mail everybody twice on every retry.
 *
 * The window is bounded at both ends. Without a floor, a session that was
 * missed while the scheduler was down would be reminded about after it had
 * already started — which is worse than not reminding at all, because the
 * email says "starts in 30 minutes" about something that finished.
 */
final class SendSessionReminders extends Command
{
    use RunsForEveryTenant;

    protected $signature = 'live:remind {--minutes=30 : How far ahead to look}';

    protected $description = 'Send reminders for live sessions starting soon, in every academy';

    public function handle(SessionAudience $audience, NotifyUsers $notify, GuestToken $tokens): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $sent = 0;

        $failed = $this->forEachTenant(function () use ($audience, $notify, $tokens, $minutes, &$sent): void {
            $sessions = LiveSession::query()
                ->upcoming()
                ->whereNull('reminder_sent_at')
                ->where('starts_at', '<=', now()->addMinutes($minutes))
                // The floor. A session already under way is not a reminder.
                ->where('starts_at', '>', now())
                ->get();

            foreach ($sessions as $session) {
                /*
                 * The audience BEFORE the claim, because an empty one has
                 * nothing to send and therefore nothing to protect against
                 * sending twice — and burning the flag on it would mean a
                 * webinar called off and then revived never reminds again
                 * (§ Patterns established in Phase 15: anything that
                 * reschedules must clear what the old schedule triggered).
                 */
                $recipients = $audience->forSession($session);
                $guests = $audience->guestsForSession($session);

                if ($recipients === [] && $guests->isEmpty()) {
                    continue;
                }

                // Claimed before sending: under-notifying a few beats mailing
                // everybody twice on every retry.
                $session->forceFill(['reminder_sent_at' => now()])->save();

                $notify->handle($recipients, new NotificationPayload(
                    type: NotificationType::SessionReminder,
                    title: $session->title.' starts soon',
                    body: 'Starting '.$session->starts_at->diffForHumans().'.',
                    actionLabel: 'Open the session',
                    actionPath: "/live/{$session->uuid}",
                    meta: [
                        'session_id' => $session->uuid,
                        'starts_at' => $session->starts_at->toIso8601String(),
                        'timezone' => $session->timezone,
                    ],
                ));

                $this->remindGuests($session, $guests, $tokens);

                $sent++;
            }
        });

        $this->info("Reminded about {$sent} session(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A guest has no bell, so the reminder is a mail carrying their manage
     * link — which is also how they join, fifteen minutes early, and how they
     * give the place up (docs/GUEST_REGISTRATION.md).
     *
     * @param  Collection<int, WebinarRegistration>  $guests
     */
    private function remindGuests(LiveSession $session, Collection $guests, GuestToken $tokens): void
    {
        $academy = (string) tenancy()->tenant?->getAttribute('slug');

        foreach ($guests as $guest) {
            $webinar = $guest->webinar;

            if ($webinar === null) {
                continue;
            }

            Notification::route('mail', $guest->email)->notify(new GuestMail(
                subject: $session->title.' starts soon',
                lines: [$webinar->title.' starts '.$session->starts_at->diffForHumans().GuestLinks::when($session).'.'],
                actionLabel: 'Join or manage my place',
                actionUrl: GuestLinks::place($academy, $webinar->slug, $tokens->place($academy, $guest, $session, now())),
            ));
        }
    }
}
