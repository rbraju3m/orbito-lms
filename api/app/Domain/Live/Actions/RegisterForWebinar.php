<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Holds a place at a webinar.
 *
 * Capacity is counted and the row inserted in ONE transaction behind a lock on
 * the webinar — the same shape as the course seat limit and the cohort. It is
 * the third time this exact race has come up, which is why it is the third
 * time it is written the same way rather than checked-then-inserted.
 *
 * IDEMPOTENT. Registering twice is somebody clicking twice, not an error: the
 * unique key on (webinar_id, email) refuses the second row and this returns
 * the first.
 *
 * A PAID webinar cannot be registered for free: the free path 423s with the
 * product to buy, and `$orderId` is how the purchase comes back in. An order
 * bypasses every refusal here — the event being closed, the room being full —
 * because by then the money has moved, and a learner who has paid and holds
 * nothing is a worse outcome than either. See `GrantOrderAccess`.
 */
final class RegisterForWebinar
{
    public function handle(User $user, Webinar $webinar, ?int $orderId = null): WebinarRegistration
    {
        return $this->hold($webinar, $user->email, $user->name, $user->id, $orderId);
    }

    /**
     * A GUEST's place: an address that has just proved it can read its own
     * mail (`ConfirmGuestRegistration`), with no account, and never bought.
     * The same transaction and the same lock as a member's, so a guest and a
     * member racing for the last place cannot both have it.
     */
    public function forGuest(Webinar $webinar, string $email, ?string $name): WebinarRegistration
    {
        return $this->hold($webinar, $email, $name, null, null);
    }

    private function hold(Webinar $webinar, string $email, ?string $name, ?int $userId, ?int $orderId): WebinarRegistration
    {
        $purchased = $orderId !== null;

        if (! $purchased) {
            $this->assertRegistrableForFree($webinar);
        }

        return DB::transaction(function () use ($webinar, $email, $name, $userId, $orderId, $purchased): WebinarRegistration {
            /** @var Webinar $locked */
            $locked = Webinar::query()->lockForUpdate()->findOrFail($webinar->id);

            $existing = WebinarRegistration::query()
                ->where('webinar_id', $locked->id)
                ->where('email', $email)
                ->first();

            if ($existing !== null) {
                /*
                 * Already registered. Reactivated rather than refused if they
                 * had cancelled — somebody changing their mind should not be
                 * told the place they gave up is gone when it is not.
                 */
                if ($existing->status !== WebinarRegistration::STATUS_REGISTERED) {
                    if (! $purchased) {
                        $this->assertHasRoom($locked);
                    }

                    $existing->forceFill([
                        'status' => WebinarRegistration::STATUS_REGISTERED,
                        'registered_at' => now(),
                        // A place bought again after a refund belongs to the
                        // NEW order, or a second refund would revoke nothing.
                        'order_id' => $orderId ?? $existing->order_id,
                    ]);
                }

                /*
                 * A place held as a GUEST becomes the member's when somebody
                 * signed in with that address registers: one person, one
                 * place — now reachable through the bell as well as by mail.
                 */
                if ($existing->user_id === null && $userId !== null) {
                    $existing->user_id = $userId;
                }

                $existing->save();

                return $existing;
            }

            if (! $purchased) {
                $this->assertHasRoom($locked);
            }

            try {
                return WebinarRegistration::create([
                    'webinar_id' => $locked->id,
                    // Null for a guest, who holds a place by address alone.
                    'user_id' => $userId,
                    // Null for a place that was never bought, which is what
                    // keeps a refund from revoking one somebody was given.
                    'order_id' => $orderId,
                    // Keyed on the email, so a guest place and the account
                    // that person later makes cannot become two places.
                    'email' => $email,
                    'name' => $name,
                    'status' => WebinarRegistration::STATUS_REGISTERED,
                    'registered_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return WebinarRegistration::query()
                    ->where('webinar_id', $locked->id)
                    ->where('email', $email)
                    ->firstOrFail();
            }
        });
    }

    /**
     * The two ways a free registration is refused.
     *
     * Neither applies to a purchase. `webinarRequiresPurchase` is a 423 and
     * not a 403 because the caller has done nothing wrong and there is a way
     * in — it carries the product so the page can offer the basket.
     */
    private function assertRegistrableForFree(Webinar $webinar): void
    {
        if (! $webinar->status->isOpen()) {
            throw LiveSessionRejected::webinarClosed();
        }

        if (! $webinar->is_paid) {
            return;
        }

        $product = $webinar->loadMissing('product')->product;

        // Published without a price is not reachable through the UI — the
        // publish rule refuses it — but a price removed afterwards would
        // otherwise be a 500 on somebody's link.
        if ($product === null || ! $product->status->isSellable()) {
            throw LiveSessionRejected::webinarNotOnSale();
        }

        throw LiveSessionRejected::webinarRequiresPurchase($product->uuid);
    }

    /** Cancelling frees the place, so this counts only live registrations. */
    private function assertHasRoom(Webinar $webinar): void
    {
        if ($webinar->capacity === null) {
            return;
        }

        $taken = WebinarRegistration::query()
            ->where('webinar_id', $webinar->id)
            ->where('status', WebinarRegistration::STATUS_REGISTERED)
            ->count();

        if ($taken >= $webinar->capacity) {
            throw LiveSessionRejected::webinarFull();
        }
    }
}
