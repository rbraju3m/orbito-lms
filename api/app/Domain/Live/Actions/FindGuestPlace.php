<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\WebinarRegistration;
use App\Domain\Live\Support\GuestToken;

/**
 * The place a guest's manage link names — or a 422, one answer for an expired
 * link, a forged one, one from another academy, and one whose address no
 * longer matches the row. The holder can do the same thing about all of them:
 * ask again from the event page.
 */
final class FindGuestPlace
{
    public function __construct(private readonly GuestToken $tokens) {}

    public function handle(string $academy, string $token): WebinarRegistration
    {
        $claim = $this->tokens->readPlace($token, $academy, now());

        $registration = $claim === null
            ? null
            : WebinarRegistration::query()
                ->with(['webinar.session', 'webinar.product.prices'])
                ->find($claim['registration']);

        if ($claim === null
            || $registration === null
            || strcasecmp($registration->email, $claim['email']) !== 0) {
            throw LiveSessionRejected::guestLinkInvalid();
        }

        return $registration;
    }
}
