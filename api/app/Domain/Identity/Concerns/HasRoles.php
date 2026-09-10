<?php

declare(strict_types=1);

namespace App\Domain\Identity\Concerns;

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleAssignment;
use App\Domain\Identity\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Role and permission resolution (ADR-07).
 *
 * Effective permissions for (user, resource) =
 *     permissions of the user's GLOBAL roles
 *   ∪ permissions of the user's roles SCOPED to that exact resource.
 *
 * Nothing outside this trait and the Policies should ask about roles at all.
 */
trait HasRoles
{
    /**
     * Per-request memo: scope cache key => set of permission keys.
     *
     * @var array<string, array<string, true>>|null
     */
    private ?array $permissionCache = null;

    /** @return HasMany<RoleAssignment, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * Active (unexpired) assignments, eager-loaded with their role's permissions.
     * Loaded once per request — every subsequent check reads the memo.
     *
     * @return Collection<int, RoleAssignment>
     */
    public function activeRoleAssignments(): Collection
    {
        if (! $this->relationLoaded('roleAssignments')) {
            $this->load(['roleAssignments' => fn ($q) => $q->active()->with('role.permissions')]);
        }

        return $this->roleAssignments
            ->reject(fn (RoleAssignment $assignment) => $assignment->hasExpired())
            ->values();
    }

    /**
     * Permission keys this user holds for the given scope.
     *
     * @return array<string, true> keyed by permission for O(1) lookup
     */
    public function effectivePermissions(?Model $scope = null): array
    {
        $cacheKey = $this->scopeCacheKey($scope);

        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        $scopeType = $scope !== null ? $scope->getMorphClass() : null;
        $scopeId = $scope?->getKey();

        $permissions = [];

        foreach ($this->activeRoleAssignments() as $assignment) {
            $applies = $assignment->isGlobal()
                || ($scopeType !== null
                    && $assignment->scope_type === $scopeType
                    && (int) $assignment->scope_id === (int) $scopeId);

            if (! $applies) {
                continue;
            }

            $role = $assignment->role;

            if ($role === null) {
                continue;
            }

            foreach ($role->permissions as $permission) {
                $permissions[$permission->key] = true;
            }
        }

        $this->permissionCache ??= [];
        $this->permissionCache[$cacheKey] = $permissions;

        return $permissions;
    }

    /**
     * The one question application code asks.
     *
     * `$scope` narrows the question to a resource: a Teaching Assistant on
     * course 42 answers true for course 42 and false for every other course.
     */
    public function hasPermission(string $permission, ?Model $scope = null): bool
    {
        return isset($this->effectivePermissions($scope)[$permission]);
    }

    /**
     * Permissions held ONLY through a role scoped to this exact resource,
     * ignoring global roles entirely.
     *
     * This answers a different question from hasPermission(): not "may they do
     * this here?" (global ∪ scoped) but "do they have a seat on THIS resource?".
     * Using the union for the second question grants every instructor rights on
     * every course, which is exactly the hole this method exists to close.
     *
     * @return array<string, true>
     */
    public function scopedPermissions(Model $scope): array
    {
        $scopeType = $scope->getMorphClass();
        $scopeId = (int) $scope->getKey();
        $permissions = [];

        foreach ($this->activeRoleAssignments() as $assignment) {
            if ($assignment->isGlobal()
                || $assignment->scope_type !== $scopeType
                || (int) $assignment->scope_id !== $scopeId) {
                continue;
            }

            $role = $assignment->role;

            if ($role === null) {
                continue;
            }

            foreach ($role->permissions as $permission) {
                $permissions[$permission->key] = true;
            }
        }

        return $permissions;
    }

    public function hasScopedPermission(string $permission, Model $scope): bool
    {
        return isset($this->scopedPermissions($scope)[$permission]);
    }

    /** @param  list<string>  $permissions */
    public function hasAnyScopedPermission(array $permissions, Model $scope): bool
    {
        $held = $this->scopedPermissions($scope);

        foreach ($permissions as $permission) {
            if (isset($held[$permission])) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $permissions */
    public function hasAnyPermission(array $permissions, ?Model $scope = null): bool
    {
        $held = $this->effectivePermissions($scope);

        foreach ($permissions as $permission) {
            if (isset($held[$permission])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether ANY active role grants ANY of these — academy-wide, or on any
     * resource at all.
     *
     * The third question, beside hasPermission() ("may they do this HERE?")
     * and hasScopedPermission() ("do they have a seat on THIS resource?"):
     * "could they EVER do this kind of thing?". It exists for the one moment
     * there is no resource to ask about yet — an upload, which happens before
     * the file is attached to anything. A Course Manager granted on one course
     * holds `curriculum.manage.own` only there, so asking with no scope would
     * wrongly answer no; asking here answers yes.
     *
     * NEVER use it to authorize an action ON a resource. It answers true for a
     * Course Manager on course 42 when the question is about course 7 — the
     * same confusion the `.own` trap has cost four times (§ Authorization).
     * Attaching the file is where the real check happens, and it happens there.
     */
    public function holdsPermissionAnywhere(string ...$permissions): bool
    {
        foreach ($this->activeRoleAssignments() as $assignment) {
            $role = $assignment->role;

            if ($role === null) {
                continue;
            }

            foreach ($role->permissions as $permission) {
                if (in_array($permission->key, $permissions, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Role membership. Deliberately NOT for authorization decisions — use
     * hasPermission() for those. This exists for seeding, admin UI and tests.
     */
    public function hasRole(RoleKey|string $role, ?Model $scope = null): bool
    {
        $key = $role instanceof RoleKey ? $role->value : $role;
        $scopeType = $scope !== null ? $scope->getMorphClass() : null;
        $scopeId = $scope?->getKey();

        return $this->activeRoleAssignments()->contains(
            fn (RoleAssignment $a) => $a->role?->key === $key
                && ($scope === null
                    ? $a->isGlobal()
                    : $a->scope_type === $scopeType && (int) $a->scope_id === (int) $scopeId)
        );
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleKey::SuperAdmin);
    }

    /** @return list<string> Sorted permission keys, for GET /auth/me. */
    public function globalPermissionKeys(): array
    {
        $keys = array_keys($this->effectivePermissions());
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    public function roleKeys(): array
    {
        return $this->activeRoleAssignments()
            ->filter(fn (RoleAssignment $a) => $a->isGlobal())
            ->map(fn (RoleAssignment $a) => $a->role?->key)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function assignRole(RoleKey|string $role, ?Model $scope = null, ?int $grantedBy = null): RoleAssignment
    {
        $key = $role instanceof RoleKey ? $role->value : $role;
        $roleModel = Role::where('key', $key)->firstOrFail();

        if ($scope !== null && ! $roleModel->isCourseScoped()) {
            throw new InvalidArgumentException("Role [{$key}] is global and cannot be scoped to a resource.");
        }

        if ($scope === null && $roleModel->isCourseScoped()) {
            throw new InvalidArgumentException("Role [{$key}] is course-scoped and requires a scope.");
        }

        $assignment = $this->roleAssignments()->firstOrCreate([
            'role_id' => $roleModel->id,
            'scope_type' => $scope?->getMorphClass(),
            'scope_id' => $scope?->getKey(),
        ], [
            'granted_by' => $grantedBy,
        ]);

        $this->forgetPermissionCache();

        return $assignment;
    }

    public function revokeRole(RoleKey|string $role, ?Model $scope = null): void
    {
        $key = $role instanceof RoleKey ? $role->value : $role;
        $roleModel = Role::where('key', $key)->first();

        if ($roleModel === null) {
            return;
        }

        $this->roleAssignments()
            ->where('role_id', $roleModel->id)
            ->where('scope_type', $scope?->getMorphClass())
            ->where('scope_id', $scope?->getKey())
            ->delete();

        $this->forgetPermissionCache();
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('roleAssignments');
    }

    /** @return list<string> Every permission key the registry defines. */
    public static function allPermissionKeys(): array
    {
        return app(PermissionRegistry::class)->allKeys();
    }

    private function scopeCacheKey(?Model $scope): string
    {
        return $scope === null ? 'global' : $scope->getMorphClass().':'.$scope->getKey();
    }
}
