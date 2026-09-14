<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\WebinarRegistration;

/**
 * The meeting link, for a guest holding a live place while the room is open.
 *
 * The same window a member joins in (`LiveSession::isJoinable()`, fifteen
 * minutes early), and nobody at an event that was called off.
 *
 * NO attendance is recorded. `session_attendance` is keyed on a central user
 * id, and a guest has none; a guest's attendance is its own slice
 * (docs/GUEST_REGISTRATION.md §6). Saying so here is cheaper than somebody
 * finding the roster short.
 */
final class JoinAsGuest
{
    public function handle(WebinarRegistration $registration): string
    {
        $registration->loadMissing('webinar.session');

        $webinar = $registration->webinar;
        $session = $webinar?->session;

        if ($registration->status !== WebinarRegistration::STATUS_REGISTERED
            || $webinar === null
            || ! $webinar->status->isOpen()
            || $session === null
            || ! $session->isJoinable()
            || $session->join_url === null) {
            throw LiveSessionRejected::notJoinable();
        }

        return $session->join_url;
    }
}
