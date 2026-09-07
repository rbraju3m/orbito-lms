<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * For a scheduled command that operates on tenant-schema data.
 *
 * The scheduler runs centrally with no tenant initialised, so a command that
 * simply queries `enrollments` or `course_progress` looks for those tables in
 * the CENTRAL database and dies. Every such command has to walk the academies
 * itself.
 *
 * Suspended and pending academies are skipped: a suspended one may be
 * mid-restore with no schema at all, and sweeping it is both pointless and a
 * way to turn a maintenance window into a crash loop.
 *
 * One academy's failure must not stop the rest. A nightly job that gives up on
 * the first bad tenant leaves every later one unswept, and the bug hides for as
 * long as that tenant stays broken.
 *
 * @phpstan-require-extends Command
 */
trait RunsForEveryTenant
{
    /**
     * @param  callable(Tenant): void  $callback
     * @return int the number of academies that threw
     */
    protected function forEachTenant(callable $callback): int
    {
        $failed = 0;

        // Whatever context we were called in. Normally none — the scheduler
        // runs centrally — but a test, or a command invoked from inside a
        // request, may already be in an academy.
        $original = tenancy()->initialized ? tenancy()->tenant : null;

        Tenant::query()
            ->where('status', TenantStatus::Active)
            ->where('is_active', true)
            ->orderBy('id')
            // The closure parameter is typed because stancl's base Tenant
            // model loses the builder's generic, and an untyped $tenants makes
            // every call on $tenant an undefined-method error.
            ->chunkById(50, function (Collection $tenants) use ($callback, &$failed): void {
                /** @var Collection<int, Tenant> $tenants */
                foreach ($tenants as $tenant) {
                    try {
                        $tenant->run(fn () => $callback($tenant));
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("[{$tenant->id}] {$e->getMessage()}");
                        report($e);
                    }
                }
            });

        /*
         * Put the caller's context back rather than blindly ending tenancy.
         *
         * stancl's Tenant::run() has no try/finally, so a callback that throws
         * leaves tenancy initialised on the academy that failed — and the LAST
         * tenant would leave the command sitting in a tenant context, with
         * everything after it reading the wrong database.
         *
         * Restoring, rather than ending, matters for one specific reason:
         * `Tenancy::initialize()` returns immediately when the tenant is
         * already active, so a caller that was inside an academy — and a
         * deployment with a single academy — never gets its connection purged.
         * Ending unconditionally would purge it, and purging discards any open
         * transaction, which under test is the caller's own fixtures.
         */
        if ($original !== null) {
            tenancy()->initialize($original);
        } elseif (tenancy()->initialized) {
            tenancy()->end();
        }

        return $failed;
    }
}
