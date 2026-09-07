<?php

declare(strict_types=1);

namespace App\Domain\Identity\Listeners;

use App\Domain\Identity\Events\UserRegistered;

/**
 * Queued via the notification itself, so registration never waits on SMTP.
 */
final class SendEmailVerification
{
    public function handle(UserRegistered $event): void
    {
        if ($event->user->hasVerifiedEmail()) {
            return;
        }

        $event->user->sendEmailVerificationNotification();
    }
}
