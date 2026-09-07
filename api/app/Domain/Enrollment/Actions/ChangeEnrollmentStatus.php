<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Events\EnrollmentExtended;
use App\Domain\Enrollment\Events\EnrollmentReinstated;
use App\Domain\Enrollment\Events\EnrollmentRevoked;
use App\Domain\Enrollment\Events\EnrollmentSuspended;
use App\Domain\Enrollment\Exceptions\EnrollmentRejected;
use App\Domain\Enrollment\Models\Enrollment;
use Carbon\CarbonInterface;

/**
 * Every legal transition of an enrollment's status, in one place — the same
 * shape as `ChangeCourseStatus` in Phase 4.
 *
 * Nothing else writes `status`, `suspended_at` or `expires_at`. Scattering
 * those assignments is how a row ends up suspended with no reason, or expired
 * with a future date.
 */
final class ChangeEnrollmentStatus
{
    public function suspend(Enrollment $enrollment, ?string $reason = null): Enrollment
    {
        if ($enrollment->status === EnrollmentStatus::Cancelled) {
            throw EnrollmentRejected::notSuspendable();
        }

        $enrollment->forceFill([
            'status' => EnrollmentStatus::Suspended,
            'suspended_at' => now(),
            'suspended_reason' => $reason,
        ])->save();

        EnrollmentSuspended::dispatch($enrollment, $reason);

        return $enrollment->refresh();
    }

    /**
     * Back to where they were, not blindly to Active: someone suspended after
     * finishing the course must not be handed an unfinished one.
     */
    public function reinstate(Enrollment $enrollment): Enrollment
    {
        $enrollment->forceFill([
            'status' => $enrollment->completed_at !== null
                ? EnrollmentStatus::Completed
                : EnrollmentStatus::Active,
            'suspended_at' => null,
            'suspended_reason' => null,
        ])->save();

        EnrollmentReinstated::dispatch($enrollment);

        return $enrollment->refresh();
    }

    /**
     * Cancelled, not deleted. The progress, the quiz attempts and the graded
     * work stay: revoking access is not a reason to destroy the record of what
     * somebody did, and re-granting a seat should return them to it.
     */
    public function revoke(Enrollment $enrollment): Enrollment
    {
        $enrollment->forceFill([
            'status' => EnrollmentStatus::Cancelled,
            'suspended_at' => null,
            'suspended_reason' => null,
        ])->save();

        EnrollmentRevoked::dispatch($enrollment);

        return $enrollment->refresh();
    }

    /**
     * Pushing the date forward also un-expires the row, because otherwise
     * "extend" would leave the learner locked out until a sweeper happened to
     * run — and access must never wait on a cron.
     */
    public function extend(Enrollment $enrollment, ?CarbonInterface $expiresAt): Enrollment
    {
        $attributes = ['expires_at' => $expiresAt];

        $reactivate = $enrollment->status === EnrollmentStatus::Expired
            && ($expiresAt === null || $expiresAt->isFuture());

        if ($reactivate) {
            $attributes['status'] = $enrollment->completed_at !== null
                ? EnrollmentStatus::Completed
                : EnrollmentStatus::Active;
        }

        $enrollment->forceFill($attributes)->save();

        EnrollmentExtended::dispatch($enrollment);

        return $enrollment->refresh();
    }
}
