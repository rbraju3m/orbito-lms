<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;

final class ChangePassword
{
    /**
     * Changing a password invalidates every issued API token. A password change
     * is how a user responds to a compromise; leaving old tokens alive would
     * defeat the point.
     */
    public function handle(User $user, string $newPassword, bool $revokeTokens = true): User
    {
        $user->forceFill(['password' => $newPassword])->save();

        if ($revokeTokens) {
            $user->tokens()->delete();
        }

        return $user;
    }
}
