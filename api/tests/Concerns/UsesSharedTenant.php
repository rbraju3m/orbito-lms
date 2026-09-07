<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;

/**
 * Gives every test a live academy without paying to build one each time.
 *
 * The naive harness creates a tenant per test: 36 tables migrated and seeded,
 * ~600 times. This provisions ONE schema per process, outside any transaction,
 * and then lets RefreshDatabase roll back the tenant connection exactly as it
 * rolls back the central one. Cost is a single migrate+seed per run; isolation
 * between tests is the same transaction boundary as before.
 *
 * The shared schema's name is deterministic, so a crashed run leaves one
 * predictable database rather than a growing pile of uuids — and the next run
 * drops it before recreating it.
 */
trait UsesSharedTenant
{
    private static ?string $sharedTenantId = null;

    /**
     * Whether this test's writes to the academy are rolled back by a
     * transaction.
     *
     * True for almost everything. A test that SWITCHES tenants must turn it
     * off — stancl purges the 'tenant' connection on every initialize(), and
     * purging a connection discards its open transaction along with every
     * uncommitted row the test had set up. See SwitchesTenants.
     */
    protected function transactsTenantConnection(): bool
    {
        return true;
    }

    /** Fixed, and per-process when tests run in parallel. */
    protected function sharedTenantId(): string
    {
        $token = ParallelTesting::token();

        return 'test'.($token ? '-'.$token : '');
    }

    /**
     * Called once per process, after migrate:fresh and BEFORE the first
     * transaction opens — which is precisely why the tenant row and its seeded
     * roles survive every subsequent rollback.
     */
    protected function provisionSharedTenant(): void
    {
        $id = $this->sharedTenantId();

        // A previous run may have died holding the schema. Creating over it
        // fails, so clear it first rather than requiring a manual drop.
        $this->dropTenantSchema($id);

        Tenant::create([
            'id' => $id,
            'slug' => 'test-academy',
            'name' => 'Test Academy',
            'status' => TenantStatus::Active,
            'is_active' => true,
        ]);

        self::$sharedTenantId = $id;
    }

    /**
     * Called before each test's transactions begin. The application is rebuilt
     * per test, so the tenant connection has to be reconfigured every time even
     * though the schema behind it is untouched.
     */
    protected function initializeSharedTenant(): void
    {
        self::$sharedTenantId ??= $this->sharedTenantId();

        $tenant = Tenant::find(self::$sharedTenantId);

        if ($tenant !== null) {
            tenancy()->initialize($tenant);
        }
    }

    /**
     * Named `tearDown<TraitName>` on purpose. Laravel invokes that through
     * `beforeApplicationDestroyed`, so it fires even when a test class defines
     * its own `tearDown()` — a plain `tearDown()` here would be silently
     * overridden by any class that has one, with no error and no warning.
     */
    protected function tearDownUsesSharedTenant(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        // Drop only schemas a test made for itself. The shared one has to
        // survive: the next test in this process is about to reuse it.
        $prefix = (string) config('tenancy.database.prefix');
        $shared = $prefix.(self::$sharedTenantId ?? $this->sharedTenantId());

        foreach ($this->tenantSchemas($prefix) as $name) {
            if ($name !== $shared) {
                DB::statement('DROP DATABASE IF EXISTS `'.$name.'`');
            }
        }
    }

    private function dropTenantSchema(string $id): void
    {
        DB::statement('DROP DATABASE IF EXISTS `'.config('tenancy.database.prefix').$id.'`');
    }

    /** @return list<string> */
    private function tenantSchemas(string $prefix): array
    {
        $rows = DB::select(
            'SELECT SCHEMA_NAME AS name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ?',
            [$prefix.'%'],
        );

        return array_map(static fn (object $row): string => (string) $row->name, $rows);
    }
}
