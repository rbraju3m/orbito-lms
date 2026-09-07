<?php

declare(strict_types=1);

namespace App\Domain\Platform\Enums;

/**
 * Where an academy sits in its lifecycle.
 *
 * Separate from `is_active`, which is the operator's kill switch. A tenant can
 * be `active` and switched off; the two answer different questions.
 */
enum TenantStatus: string
{
    /** Signed up, schema not yet provisioned or not yet approved. */
    case Pending = 'pending';
    case Active = 'active';
    /** Access closed, data intact, reinstatable without a restore. */
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting approval',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Rejected => 'Rejected',
        };
    }

    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
