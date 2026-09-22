<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * DERIVED from an invitation's timestamps and the clock — never a column
 * (§ Patterns established in Phase 15). See `Invitation::status()`.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Expired = 'expired';
    case Accepted = 'accepted';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Expired => 'Expired',
            self::Accepted => 'Accepted',
            self::Revoked => 'Revoked',
        };
    }

    /** Open: it holds the address, and it can be re-sent or revoked. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Expired;
    }
}
