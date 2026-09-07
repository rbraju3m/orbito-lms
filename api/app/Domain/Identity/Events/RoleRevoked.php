<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final class RoleRevoked
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly string $roleKey,
        public readonly ?string $scopeType,
        public readonly ?int $scopeId,
    ) {}
}
