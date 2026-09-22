<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You are invited to join …" — to an address with no account yet, so mail
 * only, sent on demand (`Notification::route('mail', …)`), the shape of
 * `GuestMail`.
 *
 * A FROZEN message (§ Patterns established in Phase 12): the words and the
 * link are decided when the invitation is issued. The link carries the only
 * copy of the token that will ever exist; the row holds its hash.
 */
final class InvitationMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $academyName,
        public readonly string $roleLabel,
        public readonly ?string $inviterName,
        public readonly string $url,
        public readonly string $expiresOn,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $opening = $this->inviterName !== null
            ? $this->inviterName.' has invited you'
            : 'You have been invited';
        $article = $this->roleLabel === 'Instructor' ? 'an' : 'a';

        return (new MailMessage)
            ->subject('You are invited to join '.$this->academyName)
            ->greeting('Hello,')
            ->line(sprintf('%s to join %s as %s %s.', $opening, $this->academyName, $article, strtolower($this->roleLabel)))
            ->action('Accept the invitation', $this->url)
            ->line('The link works until '.$this->expiresOn.'. If you were not expecting this, you can ignore it — nothing is created until you choose a password.');
    }
}
