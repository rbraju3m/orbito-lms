<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiry = (int) config('auth.passwords.users.expire', 60);

        $url = rtrim(frontend_url(), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset your '.config('app.name').' password')
            ->greeting("Hello {$notifiable->name},")
            ->line('We received a request to reset your password.')
            ->action('Reset password', $url)
            ->line("This link expires in {$expiry} minutes.")
            ->line('If you did not request a password reset, you can safely ignore this email.');
    }
}
