<?php

declare(strict_types=1);

namespace App\Domain\Live\Queries;

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything one person is expected at, in a window.
 *
 * Built by RESOLVING IDS AND THEN `whereIn`, not by `whereHas` across the
 * relations: an enrolment can name a cohort, a webinar names a session, and a
 * single statement joining all of it is both unreadable and — where the users
 * table is involved — impossible across the schema boundary
 * (§ Multi-tenancy).
 *
 * A COHORT NARROWS. Somebody enrolled in a course through cohort B does not
 * see cohort A's sessions, which is the whole reason a cohort exists.
 */
final class CalendarQuery
{
    /** @return Collection<int, LiveSession> */
    public function forUser(int $userId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $enrollments = Enrollment::query()
            ->active()
            ->where('user_id', $userId)
            ->get(['course_id', 'cohort_id']);

        $courseIds = $enrollments->pluck('course_id')->unique()->values()->all();
        $cohortIds = $enrollments->pluck('cohort_id')->filter()->unique()->values()->all();

        $webinarSessionIds = Webinar::query()
            ->whereIn('id', WebinarRegistration::query()
                ->where('user_id', $userId)
                ->where('status', WebinarRegistration::STATUS_REGISTERED)
                ->pluck('webinar_id'))
            ->whereNotNull('live_session_id')
            ->pluck('live_session_id')
            ->all();

        return LiveSession::query()
            ->with(['host', 'course', 'cohort'])
            ->whereNot('status', 'cancelled')
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->where(function ($query) use ($courseIds, $cohortIds, $webinarSessionIds, $userId): void {
                /*
                 * A session on a course the learner is enrolled in — but only
                 * one that is NOT tied to a cohort, or their own cohort. A
                 * cohort session belongs to that run, and showing it to
                 * everybody on the course is the mistake that makes cohorts
                 * pointless.
                 */
                $query
                    ->where(fn ($q) => $q->whereIn('course_id', $courseIds)->whereNull('cohort_id'))
                    ->orWhereIn('cohort_id', $cohortIds)
                    ->orWhereIn('id', $webinarSessionIds)
                    // Hosts see their own, whatever the roster says.
                    ->orWhere('host_id', $userId);
            })
            ->orderBy('starts_at')
            ->limit(500)
            ->get();
    }
}
