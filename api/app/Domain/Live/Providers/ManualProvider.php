<?php

declare(strict_types=1);

namespace App\Domain\Live\Providers;

use App\Domain\Live\Data\Meeting;
use App\Domain\Live\Data\MeetingRequest;
use App\Domain\Live\Data\ProviderAccount;
use App\Domain\Live\Exceptions\LiveSessionRejected;

/**
 * The host schedules the meeting wherever they already do, and pastes the link.
 *
 * NOT A FALLBACK — the one that works today and the one most academies will
 * use. Orbito keeps the schedule, the roster, the reminders and the
 * attendance; the video service keeps the video. That division is the reason
 * this provider is not a poor relation of the other two.
 *
 * The join link arrives on the session itself, so `create()` here REFUSES
 * rather than inventing anything: CreateLiveSession takes the host's link for
 * a host-supplied provider and never calls this. Reaching it means a caller
 * asked the wrong provider to make a meeting, which is a bug — and failing
 * loudly beats returning an empty join URL that a learner discovers at seven
 * o'clock.
 */
final class ManualProvider implements LiveSessionProvider
{
    public function create(MeetingRequest $request, ProviderAccount $account): Meeting
    {
        /*
         * Reached only if something asks a host-supplied provider to create a
         * meeting, which is a bug in the caller rather than a state to handle.
         * Failing loudly beats returning an empty join URL that a learner
         * discovers at seven o'clock.
         */
        throw LiveSessionRejected::linkRequired();
    }

    public function update(string $externalId, MeetingRequest $request, ProviderAccount $account): Meeting
    {
        throw LiveSessionRejected::linkRequired();
    }

    /** Nothing to cancel upstream: there is no upstream. */
    public function cancel(string $externalId, ProviderAccount $account): void {}
}
