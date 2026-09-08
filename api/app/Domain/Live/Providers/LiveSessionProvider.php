<?php

declare(strict_types=1);

namespace App\Domain\Live\Providers;

use App\Domain\Live\Data\Meeting;
use App\Domain\Live\Data\MeetingRequest;
use App\Domain\Live\Data\ProviderAccount;

/**
 * The seam every meeting provider fits behind.
 *
 * Three methods, because a session has three moments: it is created, it may be
 * moved, and it may be called off. Notice what is absent — there is no
 * `attendance()`. Providers report attendance in wildly different shapes and
 * some not at all, and a method every implementation had to fake would make
 * the interface a lie. Attendance is Orbito's own record (see RecordAttendance)
 * and a provider that can report it reconciles into that, later, additively.
 *
 * Implementations are resolved per academy through LiveProviderFactory,
 * because each academy connects its own account — the same shape as payments.
 */
interface LiveSessionProvider
{
    public function create(MeetingRequest $request, ProviderAccount $account): Meeting;

    /**
     * Moves an existing meeting. Returns the meeting as it now stands, because
     * some providers reissue the join URL when the time changes.
     */
    public function update(string $externalId, MeetingRequest $request, ProviderAccount $account): Meeting;

    /**
     * Calls it off at the provider.
     *
     * MUST NOT throw when the meeting is already gone. Cancelling something
     * that no longer exists is the outcome the caller wanted, and a 404 from
     * an upstream API turning into a failed cancellation would leave a session
     * cancelled here and live there.
     */
    public function cancel(string $externalId, ProviderAccount $account): void;
}
