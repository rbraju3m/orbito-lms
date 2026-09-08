<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\Models\AnalyticsEvent;
use App\Support\Console\RunsForEveryTenant;
use Illuminate\Console\Command;

/**
 * Retention. The event log is personal data and does not live forever.
 *
 * `actor_id` names a person and `ip_hash` is a pseudonym, not anonymisation —
 * the address space is small enough to enumerate given the key — so both are
 * treated as identifying and both age out together. ROLLUPS ARE NOT PRUNED:
 * they are counts with nobody in them, which is the point of building them.
 *
 * Deletes in chunks. One `DELETE` covering thirteen months of a busy academy
 * is a lock somebody notices.
 */
final class PruneAnalyticsEvents extends Command
{
    use RunsForEveryTenant;

    protected $signature = 'analytics:prune {--days= : Override the configured retention}';

    protected $description = 'Delete analytics events past their retention window, in every academy';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('orbito.analytics.retention_days'));

        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $total = 0;

        $failed = $this->forEachTenant(function () use ($cutoff, &$total): void {
            do {
                $deleted = AnalyticsEvent::query()
                    ->where('occurred_at', '<', $cutoff)
                    ->limit(5_000)
                    ->delete();

                $total += $deleted;
            } while ($deleted > 0);
        });

        $this->info("Pruned {$total} events older than {$cutoff->toDateString()}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
