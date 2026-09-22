<?php

declare(strict_types=1);

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;

/**
 * `invitation.manage` — Admin and Super Admin (docs/INVITATIONS.md §1).
 *
 * One key for the list and every write: seeing who has been invited and
 * inviting somebody are the same trust, because either tells you the other.
 */
final class InvitationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('invitation.manage');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('invitation.manage');
    }

    public function resend(User $actor, Invitation $invitation): bool
    {
        return $actor->hasPermission('invitation.manage');
    }

    public function revoke(User $actor, Invitation $invitation): bool
    {
        return $actor->hasPermission('invitation.manage');
    }
}
