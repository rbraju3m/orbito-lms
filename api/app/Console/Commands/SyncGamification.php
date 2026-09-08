<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Gamification\Support\GamificationRegistry;
use App\Support\Console\RunsForEveryTenant;
use Illuminate\Console\Command;

/**
 * Seeds every academy's rules and badges from config.
 *
 * CREATE-ONLY, the same as `permissions:sync` in spirit and deliberately not
 * in mechanism: permissions are a registry the platform owns, and these are a
 * starting point an academy is expected to retune. A sync that updated would
 * make the config file the truth and quietly revert every academy's tuning on
 * the next deploy.
 */
final class SyncGamification extends Command
{
    use RunsForEveryTenant;

    protected $signature = 'gamification:sync';

    protected $description = 'Create any missing gamification rules and badges, in every academy';

    public function handle(GamificationRegistry $registry): int
    {
        $rules = 0;
        $badges = 0;

        $failed = $this->forEachTenant(function () use ($registry, &$rules, &$badges): void {
            $created = $registry->sync();
            $rules += $created['rules'];
            $badges += $created['badges'];
        });

        $this->info("Created {$rules} rule(s) and {$badges} badge(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
