<?php

declare(strict_types=1);

namespace App\Domain\Platform\Events;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

final class TenantProvisioned
{
    use Dispatchable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $owner,
    ) {}
}
