<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Events\RoleAssigned;
use App\Domain\Identity\Exceptions\RoleAssignmentRejected;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleAssignment;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

final class AssignRoleToUser
{
    public function handle(User $user, Role $role, ?Model $scope, User $grantedBy): RoleAssignment
    {
        if ($role->isCourseScoped() && $scope === null) {
            throw RoleAssignmentRejected::scopeRequired($role->key);
        }

        if (! $role->isCourseScoped() && $scope !== null) {
            throw RoleAssignmentRejected::scopeNotAllowed($role->key);
        }

        $assignment = $user->assignRole($role->key, $scope, $grantedBy->id);

        RoleAssigned::dispatch($assignment);

        return $assignment;
    }
}
