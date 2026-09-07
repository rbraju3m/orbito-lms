<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\EmailAlreadyVerified;
use App\Domain\Identity\Exceptions\InvalidVerificationLink;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Events\Verified;

final class VerifyEmail
{
    /**
     * The signature itself is validated by the `signed` middleware before this
     * runs; here we only confirm the hash matches the address it was minted for,
     * so a valid signature for user A cannot verify user B.
     */
    public function handle(int $userId, string $hash): User
    {
        $user = User::find($userId);

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new InvalidVerificationLink;
        }

        if ($user->hasVerifiedEmail()) {
            throw new EmailAlreadyVerified;
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        return $user;
    }
}
