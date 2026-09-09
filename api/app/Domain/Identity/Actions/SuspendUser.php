<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Exceptions\PlatformOwnerProtected;

final class SuspendUser
{
    public function handle(User $user, bool $suspended): User
    {
        // Repeated from the policy on purpose: this Action is reachable from a
        // console command and from a listener, neither of which authorizes.
        if ($suspended && $user->isPlatformOwner()) {
            throw PlatformOwnerProtected::cannotBeSuspended();
        }

        $user->forceFill([
            'status' => $suspended ? UserStatus::Suspended : UserStatus::Active,
        ])->save();

        if ($suspended) {
            // Revoke API tokens and forget sessions; a suspension that leaves an
            // open session is not a suspension.
            $user->tokens()->delete();
        }

        return $user;
    }
}
