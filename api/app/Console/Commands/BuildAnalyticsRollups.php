<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\Actions\BuildDailyRollups;
use App\Domain\Analytics\Actions\BuildItemFunnel;
use App\Support\Console\RunsForEveryTenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Builds the rollups every dashboard reads (ADR-08).
 *
 * Runs twice on two schedules, which is the whole design:
 *
 *  - Nightly over YESTERDAY AND TODAY. Yesterday because it is now closed and
 *    its last events have landed; today because a queued listener that fired
 *    at 23:59 may only have been written after midnight, and a day that is
 *    never revisited would be permanently short.
 *  - Hourly over today alone, so a dashboard opened at lunchtime is not
 *    reporting yesterday.
 *
 * `--days=N` rebuilds a window, which is the recovery path: these tables hold
 * no facts, so any of them can be dropped and put back from the log and the
 * ledger.
 */
final class BuildAnalyticsRollups extends Command
{
    use RunsForEveryTenant;

    protected $signature = 'analytics:rollup
        {--date= : A single UTC date (YYYY-MM-DD)}
        {--days=2 : How many days back from today, inclusive}
        {--skip-funnel : Leave the item funnel alone}';

    protected $description = 'Build the daily analytics rollups and the item funnel, in every academy';

    public function handle(BuildDailyRollups $daily, BuildItemFunnel $funnel): int
    {
        $dates = $this->dates();

        if ($dates === []) {
            $this->error('Nothing to build — check --date and --days.');

            return self::FAILURE;
        }

        $failed = $this->forEachTenant(function () use ($daily, $funnel, $dates): void {
            foreach ($dates as $date) {
                $daily->handle($date);
            }

            if (! $this->option('skip-funnel')) {
                $funnel->handle();
            }
        });

        $this->info(sprintf(
            'Built %d day(s): %s.',
            count($dates),
            implode(', ', array_map(fn (CarbonImmutable $d): string => $d->toDateString(), $dates)),
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<CarbonImmutable> */
    private function dates(): array
    {
        // A UTC day, always. Timestamps are stored in UTC and an academy has
        // no timezone of its own, so anything else could not be rebuilt
        // deterministically — see the rollup migration.
        if (is_string($date = $this->option('date')) && $date !== '') {
            return [CarbonImmutable::parse($date, 'UTC')->startOfDay()];
        }

        $days = (int) $this->option('days');

        if ($days < 1) {
            return [];
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();

        return array_map(
            fn (int $back): CarbonImmutable => $today->subDays($back),
            range($days - 1, 0),
        );
    }
}
