<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Live\Events\WebinarDeleted;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\Webinar;
use Illuminate\Support\Facades\DB;

/**
 * Removes a webinar nobody has registered for — and refuses one somebody has.
 *
 * The same rule as a cohort, for the same reason: a place held is a record of
 * a person, and deleting it would take their registration with it silently. A
 * webinar in use is CANCELLED.
 *
 * Its session is cancelled rather than deleted, because a live session row is
 * never deleted anywhere in this domain — that is where the attendance and
 * any recording live — and cancelling it is also what tells the provider to
 * call the meeting off.
 *
 * Behind the webinar's row lock, which `RegisterForWebinar` also takes, so
 * somebody registering at the same moment either lands first and blocks the
 * delete or finds the event gone.
 */
final class DeleteWebinar
{
    public function __construct(private readonly CancelLiveSession $sessions) {}

    public function handle(Webinar $webinar): void
    {
        $session = DB::transaction(function () use ($webinar): ?LiveSession {
            /** @var Webinar $locked */
            $locked = Webinar::query()->lockForUpdate()->findOrFail($webinar->id);

            if ($locked->isInUse()) {
                throw LiveSessionRejected::webinarInUse();
            }

            $session = $locked->loadMissing('session')->session;

            $locked->delete();

            /*
             * Inside the transaction, so a rollback cannot leave Commerce
             * having retired the product of a webinar that still exists. The
             * listener is synchronous and touches one row.
             */
            WebinarDeleted::dispatch($locked->id);

            return $session;
        });

        // Outside the transaction on purpose: cancelling reaches the provider
        // over the network, and holding a row lock through somebody else's
        // HTTP timeout is how a queue of registrations backs up.
        if ($session !== null) {
            $this->sessions->handle($session);
        }
    }
}
