<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\InvitationRole;
use App\Domain\Identity\Enums\InvitationStatus;
use App\Domain\Identity\Events\InvitationSent;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\InvitationMailer;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Queries\PlanLimits;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Invite an address into the academy as a student or an instructor
 * (docs/INVITATIONS.md).
 *
 * Inviting an address that already has an OPEN invitation re-issues that one
 * — new role, new link, new expiry — rather than adding a second. Two live
 * links for one address would each grant a role, and revoking one would leave
 * the other working. `pending_email` makes that a constraint, not a check.
 *
 * An address with an ACCOUNT is refused before this runs, by the form
 * request: one account belongs to one academy (§ Multi-tenancy).
 */
final class SendInvitation
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly InvitationMailer $mailer,
    ) {}

    public function handle(string $email, InvitationRole $role, User $inviter): Invitation
    {
        $email = Invitation::normaliseEmail($email);

        if ($role === InvitationRole::Instructor) {
            $this->assertInstructorSeat($email, $this->limits);
        }

        $invitation = Invitation::query()->where('pending_email', $email)->first()
            ?? $this->create($email, $role);

        $invitation->forceFill(['role' => $role]);

        if (! $invitation->wasRecentlyCreated) {
            $invitation->sent_count++;
        }

        $this->mailer->issue($invitation, $inviter);

        InvitationSent::dispatch($invitation, $inviter->id);

        // A new row's defaults (`sent_count`, the nullable settlements) were
        // written by the database, not this model.
        return $invitation->refresh();
    }

    /**
     * An open instructor invitation holds a seat, so a plan with one seat
     * left cannot hand out five links and let all five in. Checked here and
     * NOT at acceptance: the invitee cannot change the academy's plan, and
     * the academy made its decision when it invited (§ Patterns established
     * in Phase 16 — cap the party who can do something about it).
     *
     * The address being re-issued is excluded: re-sending somebody's link
     * must not count them twice.
     */
    public static function assertInstructorSeat(string $email, PlanLimits $limits): void
    {
        $held = Invitation::query()
            ->withStatus(InvitationStatus::Pending)
            ->where('role', InvitationRole::Instructor)
            ->where('email', '!=', $email)
            ->count();

        $limits->assert(UsageMetric::Instructors, 1 + $held);
    }

    private function create(string $email, InvitationRole $role): Invitation
    {
        try {
            return Invitation::query()->create([
                'email' => $email,
                'pending_email' => $email,
                'role' => $role,
                // Replaced by the mailer before anything is sent.
                'token_hash' => Invitation::hashToken(Invitation::newToken()),
                'expires_at' => now(),
                'sent_count' => 1,
                'last_sent_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A double click: the other request made the row a moment ago.
            return Invitation::query()->where('pending_email', $email)->firstOrFail();
        }
    }
}
