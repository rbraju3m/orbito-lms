<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Events\InstructorReviewed;
use App\Domain\Identity\Exceptions\InstructorApplicationConflict;
use App\Domain\Identity\Models\InstructorProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Queries\PlanLimits;
use Illuminate\Support\Facades\DB;

/**
 * Approve, reject or block an instructor.
 *
 * The Instructor *role* is granted here and only here — approval is what makes
 * someone an instructor, not registration.
 */
final class ReviewInstructorApplication
{
    public function __construct(private readonly PlanLimits $limits) {}

    public function handle(
        InstructorProfile $profile,
        InstructorStatus $decision,
        User $reviewer,
        ?string $note = null,
    ): InstructorProfile {
        if ($decision === InstructorStatus::Pending) {
            throw InstructorApplicationConflict::notPending();
        }

        if ($decision === InstructorStatus::Approved && $profile->status === InstructorStatus::Approved) {
            throw InstructorApplicationConflict::alreadyApproved();
        }

        /*
         * The plan's instructor seats — checked only on approval, and only
         * after the already-approved case above has been ruled out, so a
         * re-approval never consumes a seat the applicant already holds.
         *
         * Rejecting and blocking are never capped: an academy at its seat
         * limit must always be able to free one.
         */
        if ($decision === InstructorStatus::Approved) {
            $this->limits->assert(UsageMetric::Instructors);
        }

        DB::transaction(function () use ($profile, $decision, $reviewer, $note): void {
            $profile->forceFill([
                'status' => $decision,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->id,
                'review_note' => $note,
            ])->save();

            $profile->loadMissing('user');
            $user = $profile->user;

            if ($decision === InstructorStatus::Approved) {
                $user->assignRole(RoleKey::Instructor, grantedBy: $reviewer->id);
            } else {
                // Rejected or blocked: the role goes away immediately. Their
                // existing courses are untouched — that is a Catalog concern.
                $user->revokeRole(RoleKey::Instructor);
            }
        });

        InstructorReviewed::dispatch($profile, $decision, $reviewer->id);

        return $profile->refresh();
    }
}
