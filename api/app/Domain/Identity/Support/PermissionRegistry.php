<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Enums\RoleScope;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reconciles the database with config/permissions.php.
 *
 * Sync is additive by design: it adds new permissions and re-attaches role
 * permissions, but never deletes a permission row that a role still uses.
 * Removing a capability is a deliberate act, not a side effect of a config edit.
 */
final class PermissionRegistry
{
    /** @return array<string, array<string, string>> */
    public function groups(): array
    {
        /** @var array<string, array<string, string>> */
        return config('permissions.groups', []);
    }

    /** @return list<string> */
    public function allKeys(): array
    {
        /** @var list<string> */
        return config('permissions.all', []);
    }

    /** @return array<string, array<string, mixed>> */
    public function roleDefinitions(): array
    {
        /** @var array<string, array<string, mixed>> */
        return config('permissions.roles', []);
    }

    /**
     * @return array{permissions_created: int, roles_created: int, roles_updated: int, orphans: list<string>}
     */
    public function sync(): array
    {
        return DB::transaction(function (): array {
            $permissionsCreated = $this->syncPermissions();
            [$rolesCreated, $rolesUpdated] = $this->syncRoles();

            return [
                'permissions_created' => $permissionsCreated,
                'roles_created' => $rolesCreated,
                'roles_updated' => $rolesUpdated,
                'orphans' => $this->orphans(),
            ];
        });
    }

    /**
     * Permission rows in the database that the config no longer defines.
     * Reported, never auto-deleted — see the class docblock.
     *
     * @return list<string>
     */
    public function orphans(): array
    {
        $defined = $this->allKeys();

        /** @var list<string> */
        return Permission::whereNotIn('key', $defined)->pluck('key')->all();
    }

    private function syncPermissions(): int
    {
        $created = 0;

        foreach ($this->groups() as $group => $permissions) {
            foreach ($permissions as $key => $description) {
                $permission = Permission::firstOrNew(['key' => $key]);
                $wasNew = ! $permission->exists;

                $permission->fill(['group' => $group, 'description' => $description])->save();

                if ($wasNew) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /** @return array{0: int, 1: int} */
    private function syncRoles(): array
    {
        $created = 0;
        $updated = 0;

        $permissionIds = Permission::pluck('id', 'key');

        foreach ($this->roleDefinitions() as $key => $definition) {
            $role = Role::firstOrNew(['key' => $key]);
            $wasNew = ! $role->exists;

            $role->fill([
                'name' => $definition['name'],
                'description' => $definition['description'] ?? null,
                'scope_kind' => RoleScope::from($definition['scope'] ?? 'global'),
                'is_system' => (bool) ($definition['system'] ?? false),
            ])->save();

            /** @var list<string> $keys */
            $keys = $definition['permissions'] ?? [];

            $unknown = array_diff($keys, $permissionIds->keys()->all());
            if ($unknown !== []) {
                throw new RuntimeException(
                    "Role [{$key}] references unknown permissions: ".implode(', ', $unknown)
                );
            }

            $role->permissions()->sync($permissionIds->only($keys)->values()->all());

            $wasNew ? $created++ : $updated++;
        }

        return [$created, $updated];
    }
}
