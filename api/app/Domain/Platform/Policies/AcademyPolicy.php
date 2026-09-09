<?php

declare(strict_types=1);

namespace App\Domain\Platform\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;

/**
 * An academy's own settings, from INSIDE it.
 *
 * Not the platform registry — that is the operator's surface, gated by the
 * central `is_super_admin` flag and reached through `/admin/tenants`. This is
 * the academy administering itself, so it is a permission like everything else
 * inside an academy, and `Gate::before` grants its Super Admin the lot.
 *
 * The caller can only ever be acting on their OWN academy: the `tenant`
 * middleware resolved the connection from their `tenant_id`, and the
 * controller reads that same id rather than accepting one from the request.
 */
final class AcademyPolicy
{
    public function view(User $actor, Tenant $academy): bool
    {
        return $actor->tenant_id === $academy->id && $actor->hasPermission('settings.view');
    }

    public function update(User $actor, Tenant $academy): bool
    {
        return $actor->tenant_id === $academy->id && $actor->hasPermission('settings.update');
    }
}
