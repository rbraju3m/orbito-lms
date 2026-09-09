<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Events\RoleRevoked;
use App\Domain\Identity\Exceptions\RoleAssignmentRejected;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleAssignment;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Exceptions\PlatformOwnerProtected;
use Illuminate\Database\Eloquent\Model;

final class RevokeRoleFromUser
{
    public function handle(User $user, Role $role, ?Model $scope = null): void
    {
        // Locking everyone out of role management is unrecoverable without
        // database access, so the last Super Admin cannot be demoted.
        if ($role->key === RoleKey::SuperAdmin->value) {
            // The platform owner's grant is not the last-holder question: it
            // must survive even in an academy with three other Super Admins,
            // because it is what makes "the owner can always get in" true.
            if ($user->isPlatformOwner()) {
                throw PlatformOwnerProtected::cannotBeDemoted();
            }

            if ($this->isLastSuperAdmin($user, $role)) {
                throw RoleAssignmentRejected::lastSuperAdmin();
            }
        }

        $user->revokeRole($role->key, $scope);

        RoleRevoked::dispatch($user, $role->key, $scope?->getMorphClass(), $scope?->getKey());
    }

    private function isLastSuperAdmin(User $user, Role $role): bool
    {
        $holders = RoleAssignment::query()
            ->where('role_id', $role->id)
            ->whereNull('scope_type')
            ->active()
            ->distinct()
            ->count('user_id');

        return $holders <= 1 && $user->hasRole(RoleKey::SuperAdmin);
    }
}
