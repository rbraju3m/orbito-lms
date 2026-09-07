<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Actions\AssignRoleToUser;
use App\Domain\Identity\Actions\RevokeRoleFromUser;
use App\Domain\Identity\Exceptions\RoleAssignmentRejected;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PermissionRegistry;
use App\Http\Requests\Identity\AssignRoleRequest;
use App\Http\Resources\Identity\RoleAssignmentResource;
use App\Http\Resources\Identity\RoleResource;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class RoleController
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);

        return ApiResponse::ok(RoleResource::collection(Role::with('permissions')->get()));
    }

    public function permissions(PermissionRegistry $registry): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);

        return ApiResponse::ok(['groups' => $registry->groups()]);
    }

    public function assignments(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return ApiResponse::ok(
            RoleAssignmentResource::collection($user->roleAssignments()->with('role')->get())
        );
    }

    public function assign(AssignRoleRequest $request, User $user, AssignRoleToUser $action): JsonResponse
    {
        $role = Role::where('key', $request->string('role')->value())->firstOrFail();

        Gate::authorize('assign', $role);

        $assignment = $action->handle($user, $role, $this->resolveScope($request), $request->user());

        return ApiResponse::created(RoleAssignmentResource::make($assignment->load('role')));
    }

    public function revoke(Request $request, User $user, Role $role, RevokeRoleFromUser $action): JsonResponse
    {
        Gate::authorize('assign', $role);

        $action->handle($user, $role, $this->resolveScope($request));

        return ApiResponse::noContent();
    }

    /**
     * Resolves a scope alias + id into the model the role is scoped to.
     * The client sends a morph-map alias, never a class name.
     *
     * A scope that cannot be resolved is an ERROR, never a silent fallback to a
     * global grant — that would quietly hand someone platform-wide rights when
     * they asked for a grant on one course.
     */
    private function resolveScope(Request $request): ?Model
    {
        $type = $request->string('scope_type')->value() ?: null;

        if ($type === null) {
            return null;
        }

        $id = $request->integer('scope_id');
        $class = Relation::getMorphedModel($type);

        $scope = $class !== null ? $class::query()->find($id) : null;

        if ($scope === null) {
            throw RoleAssignmentRejected::scopeNotFound($type, $id);
        }

        return $scope;
    }
}
