<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\TenantAction;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Exceptions\TenantTransitionRejected;
use App\Domain\Platform\Models\Tenant;

/**
 * Every legal transition of an academy's lifecycle, in one place — the same
 * shape as ChangeCourseStatus and ChangeEnrollmentStatus.
 *
 * Nothing else writes `status` or `is_active`. Suspension is reversible and
 * keeps the schema; rejection is what a pending academy gets instead.
 */
final class ChangeTenantStatus
{
    public function approve(Tenant $tenant, User $approver): Tenant
    {
        // `TenantStatus::allows()` is the single definition of what is legal;
        // TenantResource renders the same answer, so the button and the server
        // cannot disagree.
        if (! $tenant->status->allows(TenantAction::Approve)) {
            throw TenantTransitionRejected::notPending();
        }

        $tenant->update([
            'status' => TenantStatus::Active,
            'approved_at' => now(),
            'approved_by' => $approver->id,
        ]);

        return $tenant->refresh();
    }

    public function reject(Tenant $tenant, User $approver, ?string $reason = null): Tenant
    {
        if (! $tenant->status->allows(TenantAction::Reject)) {
            throw TenantTransitionRejected::notPending();
        }

        $tenant->update([
            'status' => TenantStatus::Rejected,
            'approved_by' => $approver->id,
            // Held in the JSON blob: nothing filters or sorts on it.
            'rejected_reason' => $reason,
        ]);

        return $tenant->refresh();
    }

    /**
     * Closes access, keeps everything. The schema, the courses and the
     * learners' progress all survive so reinstating is a status change rather
     * than a restore.
     */
    public function suspend(Tenant $tenant, ?string $reason = null): Tenant
    {
        if (! $tenant->status->allows(TenantAction::Suspend)) {
            throw TenantTransitionRejected::notSuspendable();
        }

        $tenant->update([
            'status' => TenantStatus::Suspended,
            'suspended_reason' => $reason,
        ]);

        return $tenant->refresh();
    }

    public function reactivate(Tenant $tenant): Tenant
    {
        if (! $tenant->status->allows(TenantAction::Reactivate)) {
            throw TenantTransitionRejected::notSuspended();
        }

        $tenant->update([
            'status' => TenantStatus::Active,
            'suspended_reason' => null,
        ]);

        return $tenant->refresh();
    }
}
