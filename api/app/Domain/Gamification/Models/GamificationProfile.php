<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Models;

use App\Support\Database\LivesInTenantSchema;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One learner's standing in this academy: their balance and their streak.
 *
 * The balance is DENORMALISED and maintained by the awarding action under a
 * row lock — never summed on a read path (§10). `points_total` and the ledger
 * are reconciled nightly, and drift between them is a bug alert rather than
 * something a user ever sees.
 *
 * @property int $user_id
 * @property int $points_total
 * @property int $current_streak_days
 * @property int $longest_streak_days
 * @property CarbonInterface|null $last_active_date
 * @property bool $is_ranked
 */
final class GamificationProfile extends Model
{
    // A CENTRAL User points at this, so it must say which schema it lives in
    // (§ Multi-tenancy).
    use LivesInTenantSchema;

    protected $table = 'gamification_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'points_total', 'current_streak_days', 'longest_streak_days',
        'last_active_date', 'is_ranked',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'points_total' => 'integer',
            'current_streak_days' => 'integer',
            'longest_streak_days' => 'integer',
            'last_active_date' => 'date',
            'is_ranked' => 'boolean',
        ];
    }
}
