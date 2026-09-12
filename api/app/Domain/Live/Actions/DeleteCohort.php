<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\Cohort;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a run that nobody's record depends on — and refuses one that does.
 *
 * `live_sessions.cohort_id` cascades, so deleting a cohort with sessions would
 * delete the sessions and their attendance with it; its learners would lose
 * the run they joined. A cohort in use is cancelled instead (409
 * `cohort_in_use`) — the same shape as a coupon somebody has used.
 *
 * Behind the cohort's row lock, which JoinCohort also takes, so a learner
 * joining at the same moment either lands first and blocks the delete, or
 * finds the run gone.
 */
final class DeleteCohort
{
    public function handle(Cohort $cohort): void
    {
        DB::transaction(function () use ($cohort): void {
            $cohort = Cohort::query()->lockForUpdate()->findOrFail($cohort->id);

            if ($cohort->isInUse()) {
                throw LiveSessionRejected::cohortInUse();
            }

            $cohort->delete();
        });
    }
}
