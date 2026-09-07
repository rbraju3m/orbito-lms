<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * The link points at the SPA, which posts the signed parameters back to
 * POST /api/v1/auth/email/verify. The API renders no HTML, so it cannot own
 * the landing page.
 */
final class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your '.config('app.name').' email address')
            ->greeting("Hello {$notifiable->name},")
            ->line('Please confirm your email address to finish setting up your account.')
            ->action('Verify email address', $this->verificationUrl($notifiable))
            ->line('This link expires in 60 minutes.')
            ->line('If you did not create an account, no further action is required.');
    }

    private function verificationUrl(object $notifiable): string
    {
        $signed = URL::temporarySignedRoute(
            'auth.email.verify',
            now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1((string) $notifiable->getEmailForVerification()),
            ],
            absolute: false,
        );

        // Hand the SPA the query string VERBATIM. Laravel validates the
        // signature against the literal query string, so re-encoding it here
        // would silently invalidate every link.
        $query = parse_url($signed, PHP_URL_QUERY) ?: '';

        return rtrim(frontend_url(), '/').'/verify-email?'.$query;
    }
}
