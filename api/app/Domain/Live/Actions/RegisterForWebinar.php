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
 */
final class RegisterForWebinar
{
    public function handle(User $user, Webinar $webinar): WebinarRegistration
    {
        if (! $webinar->status->isOpen()) {
            throw LiveSessionRejected::webinarClosed();
        }

        return DB::transaction(function () use ($user, $webinar): WebinarRegistration {
            /** @var Webinar $locked */
            $locked = Webinar::query()->lockForUpdate()->findOrFail($webinar->id);

            $existing = WebinarRegistration::query()
                ->where('webinar_id', $locked->id)
                ->where('email', $user->email)
                ->first();

            if ($existing !== null) {
                /*
                 * Already registered. Reactivated rather than refused if they
                 * had cancelled — somebody changing their mind should not be
                 * told the place they gave up is gone when it is not.
                 */
                if ($existing->status !== WebinarRegistration::STATUS_REGISTERED) {
                    $this->assertHasRoom($locked);
                    $existing->forceFill([
                        'status' => WebinarRegistration::STATUS_REGISTERED,
                        'registered_at' => now(),
                    ])->save();
                }

                return $existing;
            }

            $this->assertHasRoom($locked);

            try {
                return WebinarRegistration::create([
                    'webinar_id' => $locked->id,
                    'user_id' => $user->id,
                    // Keyed on the email so the public path in P16 and this
                    // one cannot produce two places for one person.
                    'email' => $user->email,
                    'name' => $user->name,
                    'status' => WebinarRegistration::STATUS_REGISTERED,
                    'registered_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return WebinarRegistration::query()
                    ->where('webinar_id', $locked->id)
                    ->where('email', $user->email)
                    ->firstOrFail();
            }
        });
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
