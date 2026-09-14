<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\WebinarRegistration;

/**
 * A guest giving their place up, from the manage link.
 *
 * Cancelled, not deleted — the member rule: the record that somebody was
 * coming survives and the place returns to the room. A BOUGHT place is refused
 * here exactly as it is on the member endpoint, which matters because a
 * member's row can be reached by a manage link too once the same address
 * confirms as a guest: giving up something paid for is a refund.
 */
final class CancelGuestPlace
{
    public function handle(WebinarRegistration $registration): WebinarRegistration
    {
        if ($registration->order_id !== null) {
            throw LiveSessionRejected::webinarPlacePurchased();
        }

        if ($registration->status === WebinarRegistration::STATUS_REGISTERED) {
            $registration->forceFill(['status' => WebinarRegistration::STATUS_CANCELLED])->save();
        }

        return $registration;
    }
}
