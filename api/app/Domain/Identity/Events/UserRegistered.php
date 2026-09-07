<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final class UserRegistered
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly bool $wantsToTeach = false,
    ) {}
}
