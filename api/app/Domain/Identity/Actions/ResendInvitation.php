<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\InvitationRole;
use App\Domain\Identity\Events\InvitationSent;
use App\Domain\Identity\Exceptions\InvitationClosed;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\InvitationMailer;
use App\Domain\Platform\Queries\PlanLimits;

/**
 * A fresh link for a pending or expired invitation. The old link stops
 * working in the same save — somebody who lost the mail and somebody who
 * forwarded it are both answered by one new credential.
 */
final class ResendInvitation
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly InvitationMailer $mailer,
    ) {}

    public function handle(Invitation $invitation, User $actor): Invitation
    {
        if (! $invitation->status()->isOpen()) {
            throw InvitationClosed::settled();
        }

        // Somebody may have signed up with the address since it was invited.
        if (User::query()->where('email', $invitation->email)->exists()) {
            throw InvitationClosed::accountExists();
        }

        // An EXPIRED instructor invitation held no seat; re-sending takes one.
        if ($invitation->role === InvitationRole::Instructor) {
            SendInvitation::assertInstructorSeat($invitation->email, $this->limits);
        }

        $invitation->sent_count++;
        $this->mailer->issue($invitation, $actor);

        InvitationSent::dispatch($invitation, $actor->id);

        return $invitation;
    }
}
