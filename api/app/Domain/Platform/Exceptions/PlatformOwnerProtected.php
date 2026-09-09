<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Domain\Identity\Models\User;
use App\Support\Exceptions\DomainException;

/**
 * The platform owner is not an ordinary account.
 *
 * Locking the one permanent operator out of their own installation is
 * unrecoverable without database access, so the three ways to do it — delete,
 * suspend, demote — all refuse. The refusal is repeated in the policy (so the
 * UI never offers the button), in the Action (so the console cannot do it
 * either) and on the model itself (so nothing reaches it by another path at
 * all). Any one of them alone would be a hole.
 *
 * @see User::isPlatformOwner()
 */
final class PlatformOwnerProtected extends DomainException
{
    public static function cannotBeDeleted(): self
    {
        return new self('The platform owner account cannot be deleted.');
    }

    public static function cannotBeSuspended(): self
    {
        return new self('The platform owner account cannot be suspended.');
    }

    public static function cannotBeDemoted(): self
    {
        return new self('The platform owner cannot have the Super Admin role revoked.');
    }

    public function errorCode(): string
    {
        return 'platform_owner_protected';
    }

    public function status(): int
    {
        return 422;
    }
}
