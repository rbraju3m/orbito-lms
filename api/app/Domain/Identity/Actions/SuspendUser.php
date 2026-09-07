<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;

final class SuspendUser
{
    public function handle(User $user, bool $suspended): User
    {
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
