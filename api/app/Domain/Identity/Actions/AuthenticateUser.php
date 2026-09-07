<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Events\UserLoggedIn;
use App\Domain\Identity\Exceptions\AccountSuspended;
use App\Domain\Identity\Exceptions\InvalidCredentials;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final class AuthenticateUser
{
    /**
     * Verifies credentials and returns the user. Session/token establishment is
     * the controller's job — this action decides *whether* the login is valid.
     */
    public function handle(Request $request, string $email, string $password): User
    {
        $user = User::where('email', $email)->first();

        // Hash::check against a dummy when the user is missing so the response
        // time does not reveal whether the address exists.
        $valid = $user !== null
            ? Hash::check($password, $user->password)
            : Hash::check($password, '$2y$12$fakeFakeFakeFakeFakeFOaFakeFakeFakeFakeFakeFakeFakeFa');

        if ($user === null || ! $valid) {
            throw new InvalidCredentials;
        }

        if (! $user->isActive()) {
            throw new AccountSuspended;
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        UserLoggedIn::dispatch($user, (string) $request->ip(), $request->userAgent());

        return $user;
    }
}
