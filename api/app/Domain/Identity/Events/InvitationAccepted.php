<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired beside `UserRegistered`, which every new account fires. */
final class InvitationAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly Invitation $invitation,
        public readonly User $user,
    ) {}
}
