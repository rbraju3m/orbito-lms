<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Platform\Actions\ReconcileUsageCounters as ReconcileAction;
use Illuminate\Console\Command;

final class ReconcileUsageCounters extends Command
{
    protected $signature = 'usage:reconcile {--dry-run : Report drift without correcting it}';

    protected $description = 'Recompute usage counters from source data and report drift';

    public function handle(ReconcileAction $action): int
    {
        $drift = $action->handle(dryRun: (bool) $this->option('dry-run'));

        if ($drift === []) {
            $this->components->info('Usage counters are consistent.');

            return self::SUCCESS;
        }

        $this->table(
            ['Metric', 'Owner', 'Stored', 'Actual', 'Drift'],
            array_map(array_values(...), $drift),
        );

        $this->components->warn(sprintf(
            '%d counter(s) drifted%s',
            count($drift),
            $this->option('dry-run') ? ' (dry run, nothing changed).' : ' and were corrected.',
        ));

        return self::SUCCESS;
    }
}
