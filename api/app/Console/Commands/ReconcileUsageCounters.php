<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Platform\Actions\ReconcileUsageCounters as ReconcileAction;
use App\Support\Console\RunsForEveryTenant;
use Illuminate\Console\Command;

final class ReconcileUsageCounters extends Command
{
    use RunsForEveryTenant;

    protected $signature = 'usage:reconcile {--dry-run : Report drift without correcting it}';

    protected $description = 'Recompute usage counters from source data and report drift';

    public function handle(ReconcileAction $action): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $drift = [];

        // Counters describe tenant data even though the table itself is
        // central, so the truth has to be recomputed inside each academy.
        $failed = $this->forEachTenant(function ($tenant) use ($action, $dryRun, &$drift): void {
            foreach ($action->handle(dryRun: $dryRun) as $row) {
                $drift[] = ['tenant' => $tenant->id] + $row;
            }
        });

        if ($drift === []) {
            $this->components->info('Usage counters are consistent.');

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        // The academy is the first column: drift in one tenant says nothing
        // about the others, and a bare metric name would not say which.
        $this->table(
            ['Academy', 'Metric', 'Owner', 'Stored', 'Actual', 'Drift'],
            array_map(array_values(...), $drift),
        );

        $this->components->warn(sprintf(
            '%d counter(s) drifted%s',
            count($drift),
            $dryRun ? ' (dry run, nothing changed).' : ' and were corrected.',
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
