<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Platform\Models\Tenant;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Support\Facades\DB;

/**
 * For a test that moves between academies — a scheduled command sweeping every
 * tenant, an isolation check, anything calling `Tenant::run()`.
 *
 * stancl purges the 'tenant' connection on every `initialize()`, and purging a
 * connection throws away its open transaction and every uncommitted row with
 * it. Under the normal harness the test's own fixtures vanish mid-test and the
 * symptom is a bewildering "property on null" rather than anything naming
 * transactions.
 *
 * So these tests do not transact the academy at all. They COMMIT, and this
 * trait puts the schema back afterwards — which is slower, and is why it is
 * opt-in per file rather than the default for all six hundred.
 */
trait SwitchesTenants
{
    protected function transactsTenantConnection(): bool
    {
        return false;
    }

    protected function tearDownSwitchesTenants(): void
    {
        $tenant = Tenant::find($this->sharedTenantId());

        if ($tenant === null) {
            return;
        }

        $tenant->run(function (): void {
            $tables = array_map(
                static fn (object $row): string => (string) array_values((array) $row)[0],
                DB::select('SHOW TABLES'),
            );

            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach ($tables as $table) {
                // `migrations` is the schema's own record of itself; wiping it
                // would make the next run think the academy is unmigrated.
                if ($table !== 'migrations') {
                    DB::table($table)->truncate();
                }
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            // Roles and permissions were seeded at provisioning and have just
            // been truncated with everything else; without this the next test
            // in the file has an academy nobody can do anything in.
            (new TenantDatabaseSeeder)->run();
        });
    }
}
