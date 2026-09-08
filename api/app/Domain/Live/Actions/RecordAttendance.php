<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Live\Enums\AttendanceSource;
use App\Domain\Live\Events\AttendanceRecorded;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\SessionAttendance;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Records that somebody was there.
 *
 * ATTENDANCE IS ORBITO'S OWN RECORD, not a provider's. Zoom reports it in one
 * shape, Meet in another, and the manual provider cannot report it at all —
 * so the thing every provider has in common is the click on Join, and that is
 * what this writes. A provider that can report attendance reconciles INTO
 * this later, additively, which is why `source` exists.
 *
 * Rejoining EXTENDS the row rather than adding one: somebody whose connection
 * drops attended once, and a compliance report that counted them twice would
 * be worse than useless.
 */
final class RecordAttendance
{
    /** @return array{0: SessionAttendance, 1: bool} the row, and whether it is new */
    public function handle(
        LiveSession $session,
        int $userId,
        AttendanceSource $source = AttendanceSource::SelfJoin,
    ): array {
        try {
            $attendance = SessionAttendance::create([
                'live_session_id' => $session->id,
                'user_id' => $userId,
                'joined_at' => now(),
                'duration_seconds' => 0,
                'source' => $source,
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             * Already here. Caught rather than checked: two tabs, or a double
             * click, both find nothing and both insert — and the constraint is
             * what makes one of them lose.
             */
            $existing = SessionAttendance::query()
                ->where('live_session_id', $session->id)
                ->where('user_id', $userId)
                ->firstOrFail();

            /*
             * A host marking a roster OVERRIDES a click, because a person
             * saying "they were there" is better evidence than a link being
             * opened. A click never downgrades a host's mark.
             */
            if ($source === AttendanceSource::Host && $existing->source !== AttendanceSource::Host) {
                $existing->forceFill(['source' => $source])->save();
            }

            return [$existing, false];
        }

        AttendanceRecorded::dispatch($attendance);

        return [$attendance, true];
    }

    /**
     * Closes the row when somebody leaves.
     *
     * `duration_seconds` only ever GROWS, the same rule as `watch_max_seconds`
     * in the player: somebody who joins, leaves and rejoins has attended for
     * the span of their longest visit at least, and a second visit must not
     * shorten the record of the first.
     */
    public function leave(LiveSession $session, int $userId): ?SessionAttendance
    {
        $attendance = SessionAttendance::query()
            ->where('live_session_id', $session->id)
            ->where('user_id', $userId)
            ->first();

        if ($attendance === null) {
            return null;
        }

        $seconds = (int) $attendance->joined_at->diffInSeconds(now());

        $attendance->forceFill([
            'left_at' => now(),
            'duration_seconds' => max($attendance->duration_seconds, $seconds),
        ])->save();

        return $attendance;
    }
}
