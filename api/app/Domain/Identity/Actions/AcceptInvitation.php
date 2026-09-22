<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\RegisterUserData;
use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Enums\InvitationRole;
use App\Domain\Identity\Enums\InvitationStatus;
use App\Domain\Identity\Events\InvitationAccepted;
use App\Domain\Identity\Exceptions\InvitationUnusable;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turn an invitation into an account (docs/INVITATIONS.md §3).
 *
 * The account is made by `RegisterUser`, like every other — there is one way
 * an account comes to exist. What an invitation adds runs as that action's
 * `$first` step, inside the academy and under its compensation:
 *
 *  1. CLAIM the row with a conditional UPDATE. Two tabs accepting one link
 *     both reach here; exactly one claims it, and the other's account is
 *     deleted by `RegisterUser` when this throws. A revoke that lands first
 *     wins the same way.
 *  2. For an instructor, approve them through `ReviewInstructorApplication`,
 *     the one place the Instructor role is granted, with the seat the
 *     invitation already held.
 *
 * The address is the INVITATION's, never the form's: following the link
 * proved that mailbox, which is also why the account starts verified.
 */
final class AcceptInvitation
{
    public function __construct(
        private readonly RegisterUser $register,
        private readonly ReviewInstructorApplication $review,
    ) {}

    public function handle(Tenant $academy, string $token, RegisterUserData $data): User
    {
        $invitation = $academy->run(fn () => $this->usable($token));

        if (User::query()->where('email', $invitation->email)->exists()) {
            throw InvitationUnusable::accountExists();
        }

        $account = new RegisterUserData(
            name: $data->name,
            email: $invitation->email,
            password: $data->password,
            wantsToTeach: false,
            timezone: $data->timezone,
            locale: $data->locale,
            emailVerified: true,
        );

        try {
            $user = $this->register->handle($account, $academy, fn (User $user) => $this->grant($invitation, $user));
        } catch (UniqueConstraintViolationException) {
            // The address signed up between the check above and the insert.
            throw InvitationUnusable::accountExists();
        }

        $academy->run(fn () => InvitationAccepted::dispatch($invitation->refresh(), $user));

        return $user;
    }

    /** The row behind a token, or why it cannot be used. Runs inside the academy. */
    public function usable(string $token): Invitation
    {
        $invitation = Invitation::findByToken($token) ?? throw InvitationUnusable::unknown();

        if ($invitation->status() !== InvitationStatus::Pending) {
            throw $this->refusal($invitation);
        }

        return $invitation;
    }

    private function refusal(Invitation $invitation): InvitationUnusable
    {
        return match ($invitation->status()) {
            InvitationStatus::Expired => InvitationUnusable::expired(),
            InvitationStatus::Revoked => InvitationUnusable::revoked(),
            InvitationStatus::Accepted, InvitationStatus::Pending => InvitationUnusable::accepted(),
        };
    }

    private function grant(Invitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user): void {
            $claimed = Invitation::query()
                ->whereKey($invitation->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update([
                    'accepted_at' => now(),
                    'accepted_user_id' => $user->id,
                    'pending_email' => null,
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                // Lost to the other tab, or revoked or expired a moment ago.
                // Say which.
                throw $this->refusal($invitation->refresh());
            }

            if ($invitation->role !== InvitationRole::Instructor) {
                return;
            }

            $profile = $user->instructorProfile()->create([
                'status' => InstructorStatus::Pending,
                'applied_at' => now(),
                'application_source' => 'invitation',
            ]);

            $this->review->handle(
                $profile,
                InstructorStatus::Approved,
                $invitation->invited_by !== null ? User::query()->find($invitation->invited_by) : null,
                seatHeld: true,
            );
        });
    }
}
