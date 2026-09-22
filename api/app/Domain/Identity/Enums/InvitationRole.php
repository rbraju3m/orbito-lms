<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * What an invitation makes somebody (docs/INVITATIONS.md §1).
 *
 * A closed set of two on purpose. Admin and Staff are powers somebody should
 * be given by a person looking at their account, from the roles screen — not
 * by whoever happens to be holding a link that was forwarded once.
 */
enum InvitationRole: string
{
    case Student = 'student';
    case Instructor = 'instructor';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Student',
            self::Instructor => 'Instructor',
        };
    }
}
