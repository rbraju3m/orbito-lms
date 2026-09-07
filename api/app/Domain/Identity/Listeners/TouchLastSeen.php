<?php

declare(strict_types=1);

namespace App\Domain\Identity\Listeners;

use App\Domain\Identity\Events\UserLoggedIn;

final class TouchLastSeen
{
    public function handle(UserLoggedIn $event): void
    {
        // Placeholder for the audit log (Phase 19). Recorded here so the seam
        // exists at the moment sign-ins start happening.
        logger()->info('user.logged_in', [
            'user_id' => $event->user->id,
            'ip' => $event->ip,
        ]);
    }
}
