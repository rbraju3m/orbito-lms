<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\Invitation;
use Illuminate\Foundation\Events\Dispatchable;

final class InvitationRevoked
{
    use Dispatchable;

    public function __construct(
        public readonly Invitation $invitation,
        public readonly ?int $actorId,
    ) {}
}
