<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Gamification\Actions\BuildLeaderboards;
use App\Support\Console\RunsForEveryTenant;
use Illuminate\Console\Command;

/**
 * Rebuilds every board in every academy.
 *
 * Hourly. A leaderboard is a snapshot on purpose — computing one live is a sum
 * over the whole ledger per page load, and a board that reshuffles while
 * somebody reads it is worse than one that is an hour old.
 */
final class BuildLeaderboardsCommand extends Command
{
    use RunsForEveryTenant;

    protected $signature = 'gamification:leaderboards';

    protected $description = 'Rebuild the leaderboard snapshots in every academy';

    public function handle(BuildLeaderboards $build): int
    {
        $boards = 0;

        $failed = $this->forEachTenant(function () use ($build, &$boards): void {
            $boards += $build->handle();
        });

        $this->info("Built {$boards} board(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
