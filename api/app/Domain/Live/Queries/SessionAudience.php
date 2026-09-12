<?php

declare(strict_types=1);

namespace App\Domain\Live\Queries;

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Live\Enums\WebinarStatus;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use Illuminate\Support\Collection;

/**
 * Who is expected at a session.
 *
 * One place, because three things ask it — the reminder, the roster, and the
 * "may I join?" check — and three answers would eventually disagree about
 * whether a cohort member counts.
 *
 * A COHORT NARROWS the audience. A session attached to one is for that run
 * only; a session attached to the course is for everybody enrolled in it. A
 * session with neither is a webinar's, and its audience is its registrations.
 */
final class SessionAudience
{
    /** @return list<int> central user ids */
    public function forSession(LiveSession $session): array
    {
        if ($session->cohort_id !== null) {
            return $this->ids(Enrollment::query()
                ->active()
                ->where('cohort_id', $session->cohort_id)
                ->pluck('user_id'));
        }

        if ($session->course_id !== null) {
            return $this->ids(Enrollment::query()
                ->active()
                ->where('course_id', $session->course_id)
                ->pluck('user_id'));
        }

        $webinar = Webinar::query()->where('live_session_id', $session->id)->first();

        if ($webinar === null) {
            return [];
        }

        /*
         * NOBODY is expected at an event that was called off, and this is the
         * one place that has to say so: the reminder, the roster and "may I
         * join?" all read it, so a cancelled webinar stops reminding, stops
         * admitting people and stops filling a roster without three separate
         * checks that could disagree.
         *
         * The SESSION row is deliberately left alone — `scheduled`, with its
         * meeting intact. Cancelling it would be the tidier-looking move and
         * it is a trap: the provider meeting is deleted with it, nothing in
         * the product can reschedule a webinar's session (editing a webinar
         * never touches the time), and `WebinarStatus::allows()` lets a
         * cancelled event be revived. Reviving would then be a dead end.
         * Asking the webinar means revival restores everything by itself.
         */
        if ($webinar->status === WebinarStatus::Cancelled) {
            return [];
        }

        return $this->ids(WebinarRegistration::query()
            ->where('webinar_id', $webinar->id)
            ->where('status', WebinarRegistration::STATUS_REGISTERED)
            ->whereNotNull('user_id')
            ->pluck('user_id'));
    }

    public function includes(LiveSession $session, int $userId): bool
    {
        // The host is always in the room, whatever the roster says.
        return $session->host_id === $userId
            || in_array($userId, $this->forSession($session), true);
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return list<int>
     */
    private function ids(Collection $ids): array
    {
        return $ids->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
    }
}
