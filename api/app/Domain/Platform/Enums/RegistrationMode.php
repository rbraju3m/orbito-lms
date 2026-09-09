<?php

declare(strict_types=1);

namespace App\Domain\Platform\Enums;

/**
 * Who may create an account in an academy.
 *
 * This exists because tenancy resolves from the AUTHENTICATED user, and
 * registration has none — so `POST /auth/register` had no way to know which
 * academy it was registering into. It wrote the central user row with a null
 * `tenant_id` and assigned the Student role into whichever academy happened to
 * be open, which under test was the harness's shared one and in a real
 * deployment was none at all (§ Multi-tenancy).
 *
 * The academy is now named in the request, which turns "which academy?" into
 * "may anyone join THIS one?" — a question the academy has to be able to
 * answer for itself.
 */
enum RegistrationMode: string
{
    /** Anyone holding the academy's signup link may join, as a Student. */
    case Open = 'open';

    /**
     * Only somebody holding an invitation.
     *
     * DECLARED, NOT BUILT. There is no invitations table and no accept flow;
     * selecting this mode closes self-registration and says so. The case is
     * here so the seam is visible and so an academy that sets it does not
     * silently fall back to Open — the same reason `ItemType` declared quiz
     * and assignment three phases before they existed.
     */
    case Invite = 'invite';

    /** Nobody self-registers. Accounts are created by an academy admin. */
    case Closed = 'closed';

    /**
     * What an academy gets if it has never chosen.
     *
     * Open, because an academy exists in order to have members and the slug is
     * the link it hands out; a closed default would leave every newly
     * provisioned academy unable to gain a single one. Joining grants the
     * Student role and nothing else: a paid course still has to be bought.
     */
    public static function default(): self
    {
        return self::Open;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Anyone with the link',
            self::Invite => 'Invitation only',
            self::Closed => 'Nobody — admins create accounts',
        };
    }

    public function allowsSelfSignup(): bool
    {
        return $this === self::Open;
    }
}
