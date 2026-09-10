<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Events\EnrollmentAccessChanged;
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

        $wasGranting = $enrollment->status->grantsAccess();

        $enrollment->forceFill([
            'status' => EnrollmentStatus::Suspended,
            'suspended_at' => now(),
            'suspended_reason' => $reason,
        ])->save();

        EnrollmentSuspended::dispatch($enrollment, $reason);
        $this->announceAccess($enrollment, $wasGranting);

        return $enrollment->refresh();
    }

    /**
     * Back to where they were, not blindly to Active: someone suspended after
     * finishing the course must not be handed an unfinished one.
     */
    public function reinstate(Enrollment $enrollment): Enrollment
    {
        $wasGranting = $enrollment->status->grantsAccess();

        $enrollment->forceFill([
            'status' => $enrollment->completed_at !== null
                ? EnrollmentStatus::Completed
                : EnrollmentStatus::Active,
            'suspended_at' => null,
            'suspended_reason' => null,
        ])->save();

        EnrollmentReinstated::dispatch($enrollment);
        $this->announceAccess($enrollment, $wasGranting);

        return $enrollment->refresh();
    }

    /**
     * Cancelled, not deleted. The progress, the quiz attempts and the graded
     * work stay: revoking access is not a reason to destroy the record of what
     * somebody did, and re-granting a seat should return them to it.
     */
    public function revoke(Enrollment $enrollment): Enrollment
    {
        $wasGranting = $enrollment->status->grantsAccess();

        $enrollment->forceFill([
            'status' => EnrollmentStatus::Cancelled,
            'suspended_at' => null,
            'suspended_reason' => null,
        ])->save();

        EnrollmentRevoked::dispatch($enrollment);
        $this->announceAccess($enrollment, $wasGranting);

        return $enrollment->refresh();
    }

    /**
     * Pushing the date forward also un-expires the row, because otherwise
     * "extend" would leave the learner locked out until a sweeper happened to
     * run — and access must never wait on a cron.
     */
    public function extend(Enrollment $enrollment, ?CarbonInterface $expiresAt): Enrollment
    {
        $wasGranting = $enrollment->status->grantsAccess();
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
        $this->announceAccess($enrollment, $wasGranting);

        return $enrollment->refresh();
    }

    /**
     * Announce a real flip, and only a real flip.
     *
     * Every method above is reachable with nothing to change — suspending a
     * suspended row, extending a live one — and a tally that treats the
     * operation as the transition double-counts on exactly those calls. This
     * compares before with after and stays quiet when they agree.
     */
    private function announceAccess(Enrollment $enrollment, bool $wasGranting): void
    {
        $grantsAccess = $enrollment->status->grantsAccess();

        if ($grantsAccess !== $wasGranting) {
            EnrollmentAccessChanged::dispatch($enrollment, $grantsAccess);
        }
    }
}
