<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Enrollment\Actions\EnrollInCourse;
use App\Domain\Enrollment\Data\EnrollmentIntent;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\Cohort;

/**
 * Enrols somebody into one scheduled run.
 *
 * A thin wrapper on purpose. It does NOT re-implement enrolment — payment,
 * prerequisites, the course seat limit and the duplicate check all still
 * belong to EnrollInCourse, and a second path that skipped any of them would
 * be a way in that nobody remembered to close.
 *
 * The cohort's own capacity is checked inside that action's transaction, not
 * here: checking first and enrolling second is exactly the race two people
 * clicking at once creates.
 */
final class JoinCohort
{
    public function __construct(private readonly EnrollInCourse $enroll) {}

    public function handle(User $user, Cohort $cohort, ?EnrollmentIntent $intent = null): Enrollment
    {
        $cohort->loadMissing('course');
        $course = $cohort->course;

        if ($course === null) {
            throw LiveSessionRejected::cohortClosed();
        }

        /*
         * A cheap pre-check so the common refusal is a clean 409 rather than a
         * transaction that opens and rolls back. The authoritative check is
         * still the one under the row lock.
         */
        if (! $cohort->status->isEnrollable()) {
            throw LiveSessionRejected::cohortClosed();
        }

        return $this->enroll->handle(
            $user,
            $course,
            ($intent ?? EnrollmentIntent::free())->forCohort($cohort->id),
        );
    }
}
