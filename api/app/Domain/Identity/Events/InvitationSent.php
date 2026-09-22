<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\Invitation;
use Illuminate\Foundation\Events\Dispatchable;

/** Issued or re-issued: a link is now in somebody's inbox. */
final class InvitationSent
{
    use Dispatchable;

    public function __construct(
        public readonly Invitation $invitation,
        public readonly ?int $actorId,
    ) {}
}
