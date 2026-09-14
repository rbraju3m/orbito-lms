<?php

declare(strict_types=1);

namespace App\Domain\Live\Notifications;

use App\Domain\Notification\Notifications\DomainNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A message to somebody with an email address and no account — a guest at a
 * webinar (docs/GUEST_REGISTRATION.md).
 *
 * NOT a `DomainNotification`. That class's `via()` asks a person's
 * preferences, keyed on a central user id a guest does not have, and its
 * database channel writes to a bell nobody can open. This one is mail only,
 * sent on demand (`Notification::route('mail', …)`).
 *
 * A FROZEN message, like every notification here (§ Patterns established in
 * Phase 12): the words and the link are decided by the caller when the thing
 * happens, so nothing is re-read in a worker.
 *
 * Every guest mail that concerns a held place carries its manage link, and
 * that link is how a guest gets out — there are no notification settings to
 * send them to.
 */
final class GuestMail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $lines  Before the button.
     * @param  list<string>  $after  After it.
     */
    public function __construct(
        public readonly string $subject,
        public readonly array $lines,
        public readonly ?string $actionLabel = null,
        public readonly ?string $actionUrl = null,
        public readonly array $after = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting('Hello,');

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        if ($this->actionLabel !== null && $this->actionUrl !== null) {
            $mail->action($this->actionLabel, $this->actionUrl);
        }

        foreach ($this->after as $line) {
            $mail->line($line);
        }

        return $mail;
    }
}
