<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Support;

use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;

/**
 * Whether this person can be sold a place at this event, right now.
 *
 * ONE definition, asked twice — by `AddToCart` so the refusal arrives at the
 * moment of adding, and again by `PlaceOrder` because anything can change
 * between the basket and the button. Neither call may be dropped in favour of
 * the other: that one is for the human, this one is for correctness.
 *
 * It is deliberately NOT asked a third time, when the payment lands. By then
 * the money has moved, and a room with one extra person in it is a smaller
 * problem than a learner who has paid and holds nothing (`GrantOrderAccess`).
 * Nothing here is atomic against a concurrent purchase, and it cannot be
 * without reserving a place across a payment redirect — which is its own
 * slice, and a queue of expiring holds.
 */
final class WebinarPurchase
{
    public static function assertBuyable(User $user, Webinar $webinar): void
    {
        $webinar->loadMissing('session');

        if (self::alreadyHolds($user, $webinar)) {
            throw CheckoutRejected::alreadyOwned($webinar->title);
        }

        // Derived from the clock, never swept (§ Patterns established in
        // Phase 15) — which is why it is asked here rather than expected to
        // show up in the product's status.
        if ($webinar->session?->ends_at->isPast() === true) {
            throw CheckoutRejected::webinarOver($webinar->title);
        }

        if ($webinar->placesRemaining() === 0) {
            throw CheckoutRejected::webinarFull($webinar->title);
        }
    }

    /**
     * Matched on EMAIL, like the unique key and like the register endpoint, so
     * a place held before somebody had an account still counts as theirs.
     */
    public static function alreadyHolds(User $user, Webinar $webinar): bool
    {
        return WebinarRegistration::query()
            ->where('webinar_id', $webinar->id)
            ->where('email', $user->email)
            ->live()
            ->exists();
    }
}
