<?php

declare(strict_types=1);

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;

final class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('role.view');
    }

    public function view(User $actor): bool
    {
        return $actor->hasPermission('role.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('role.create');
    }

    public function update(User $actor, Role $role): bool
    {
        return ! $role->is_system && $actor->hasPermission('role.update');
    }

    public function delete(User $actor, Role $role): bool
    {
        return ! $role->is_system && $actor->hasPermission('role.delete');
    }

    /**
     * Global roles need `role.assign`; course-scoped roles need
     * `role.assign.course`, which an instructor holds for their own courses.
     */
    public function assign(User $actor, Role $role): bool
    {
        // Only an existing Super Admin can mint another one.
        if ($role->key === 'super_admin') {
            return $actor->isSuperAdmin();
        }

        return $role->isCourseScoped()
            ? $actor->hasPermission('role.assign.course')
            : $actor->hasPermission('role.assign');
    }
}
