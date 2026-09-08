<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Models;

use App\Domain\Gamification\Enums\LeaderboardPeriod;
use App\Domain\Gamification\Enums\LeaderboardScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A board, computed and frozen.
 *
 * `entries` is the WHOLE board as JSON — written once, read whole, never
 * queried into. Computing one live would be a sum over the entire ledger on
 * every page load, and it would also mean the board reshuffles under somebody
 * while they are reading it.
 *
 * Display names are frozen in at build time rather than joined on read: they
 * live in the CENTRAL users table, and a join across the boundary compiles to
 * one statement and cannot work (§ Multi-tenancy). A rename shows up on the
 * next build, which is what a snapshot means.
 *
 * @property int $id
 * @property LeaderboardScope $scope
 * @property int|null $scope_id
 * @property LeaderboardPeriod $period
 * @property CarbonInterface $period_start
 * @property list<array<string, mixed>> $entries
 * @property CarbonInterface $computed_at
 */
final class LeaderboardSnapshot extends Model
{
    protected $table = 'leaderboard_snapshots';

    protected $fillable = ['scope', 'scope_id', 'period', 'period_start', 'entries', 'computed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => LeaderboardScope::class,
            'period' => LeaderboardPeriod::class,
            'period_start' => 'date',
            'entries' => 'array',
            'computed_at' => 'datetime',
        ];
    }
}
