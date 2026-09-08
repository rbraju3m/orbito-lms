<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Queries\SessionAudience;
use App\Domain\Notification\Actions\NotifyUsers;
use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Enums\NotificationType;
use App\Support\Console\RunsForEveryTenant;
use Illuminate\Console\Command;

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

    public function handle(SessionAudience $audience, NotifyUsers $notify): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $sent = 0;

        $failed = $this->forEachTenant(function () use ($audience, $notify, $minutes, &$sent): void {
            $sessions = LiveSession::query()
                ->upcoming()
                ->whereNull('reminder_sent_at')
                ->where('starts_at', '<=', now()->addMinutes($minutes))
                // The floor. A session already under way is not a reminder.
                ->where('starts_at', '>', now())
                ->get();

            foreach ($sessions as $session) {
                // Claimed first: under-notifying a few beats mailing everybody
                // twice on every retry.
                $session->forceFill(['reminder_sent_at' => now()])->save();

                $recipients = $audience->forSession($session);

                if ($recipients === []) {
                    continue;
                }

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

                $sent++;
            }
        });

        $this->info("Reminded about {$sent} session(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
