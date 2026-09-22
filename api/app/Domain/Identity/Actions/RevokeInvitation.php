<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Events\InvitationRevoked;
use App\Domain\Identity\Exceptions\InvitationClosed;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;

/**
 * Withdraw an invitation. The row stays — who was invited, by whom, and that
 * it was withdrawn is history — and `pending_email` is released so the
 * address can be invited again later.
 */
final class RevokeInvitation
{
    public function handle(Invitation $invitation, User $actor): Invitation
    {
        // Conditional, so a revoke racing an acceptance cannot undo it.
        $revoked = Invitation::query()
            ->whereKey($invitation->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'pending_email' => null, 'updated_at' => now()]);

        if ($revoked === 0) {
            throw InvitationClosed::settled();
        }

        $invitation->refresh();

        InvitationRevoked::dispatch($invitation, $actor->id);

        return $invitation;
    }
}
