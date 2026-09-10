<?php

declare(strict_types=1);

namespace App\Domain\Platform\Listeners;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Events\CourseEnrolled;
use App\Domain\Enrollment\Events\EnrollmentAccessChanged;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Support\UsageCounters;

/**
 * How many DISTINCT people can currently learn here.
 *
 * A student is a person, not an enrolment: somebody taking four courses is
 * one seat, which is what an academy is sold and what it expects to see.
 * "Currently" means holding at least one enrolment that grants access —
 * active or completed. A suspended or revoked learner cannot open anything,
 * so an academy that revokes somebody gets the seat back rather than paying
 * for a row nobody can use.
 *
 * The counter is academy-wide only. Per-owner would read "this instructor's
 * students", which is an analytics figure with a different definition — and
 * summing it across instructors would count a shared learner twice.
 *
 * Two events, and the pairing is the whole design. `CourseEnrolled` is a new
 * row, so it can only ever be a gain. Everything else arrives as
 * `EnrollmentAccessChanged`, which Enrollment fires ONLY when access really
 * flipped — suspending an already-suspended row and extending a live one both
 * stay silent, and those are exactly the calls that would otherwise
 * double-count. Given that guarantee, one question settles both directions:
 * does this person hold any OTHER enrolment that grants access? None means
 * this event took them over the line, whichever way it was going.
 *
 * Not atomic, deliberately — two enrolments landing together can both see
 * none. `usage:reconcile` recomputes from source nightly and reports the
 * drift, the same discipline as every other counter here.
 */
final class TrackStudentUsage
{
    public function __construct(private readonly UsageCounters $counters) {}

    /** A brand new enrolment. Always granting, so only ever a gain. */
    public function enrolled(CourseEnrolled $event): void
    {
        if ($this->otherGrantingEnrollments($event->enrollment) === 0) {
            $this->counters->increment(UsageMetric::Students);
        }
    }

    public function accessChanged(EnrollmentAccessChanged $event): void
    {
        if ($this->otherGrantingEnrollments($event->enrollment) > 0) {
            // Still a student on the strength of something else. Nothing to do
            // in either direction.
            return;
        }

        $event->grantsAccess
            ? $this->counters->increment(UsageMetric::Students)
            : $this->counters->decrement(UsageMetric::Students);
    }

    /** Their access-granting enrolments, not counting this one. */
    private function otherGrantingEnrollments(Enrollment $enrollment): int
    {
        return Enrollment::query()
            ->where('user_id', $enrollment->user_id)
            ->whereKeyNot($enrollment->getKey())
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
            ->count();
    }
}
