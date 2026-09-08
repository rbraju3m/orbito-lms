<?php

declare(strict_types=1);

namespace App\Domain\Notification\Notifications;

use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ONE notification class, carrying a payload — not one subclass per type.
 *
 * Five near-identical classes differing only in their strings would be five
 * places for the in-app text and the email text to drift apart. Here the
 * payload is built once by the listener that knows the event, and both
 * channels render the same three fields, so an entry in the bell and the
 * email beside it can never say different things.
 *
 * It carries scalars only. Nothing is re-queried on the queue, so no relation
 * can be lazily loaded in a worker and no model can have changed underneath a
 * message that was already sent (see NotificationPayload).
 *
 * Adding a type is a case in NotificationType plus a listener. A type that
 * genuinely needs its own email layout can subclass this and override
 * toMail() — nothing here prevents that, and until one does, it would be
 * four empty classes.
 */
final class DomainNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly NotificationPayload $payload) {}

    /**
     * The switches decide. `via()` is the enforcement point rather than the
     * listener, so a notification sent from anywhere — a command, a future
     * digest, a test — obeys preferences without having to remember to ask.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return app(NotificationPreferences::class)
            ->channelsFor((int) $notifiable->getKey(), $this->payload->type);
    }

    /**
     * The stored `type` column. A stable key, not `get_class($this)` — which
     * would be the same string for every notification here anyway.
     */
    public function databaseType(object $notifiable): string
    {
        return $this->payload->type->value;
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload->toArray();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->payload->title)
            ->greeting('Hello '.($notifiable->name ?? 'there').',')
            ->line($this->payload->body);

        if ($this->payload->actionPath !== null) {
            $mail->action($this->payload->actionLabel ?? 'Open', (string) $this->payload->url());
        }

        // Every email says how to stop receiving it. An unsubscribe somebody
        // cannot find is a spam complaint with extra steps.
        return $mail->line('You can choose which emails you receive in your notification settings.');
    }
}
