<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the ledger.
 *
 * APPEND-ONLY. Points are never edited; taking some back is a negative row,
 * so the history says what happened rather than quietly disagreeing with the
 * balance somebody saw yesterday.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $rule_id
 * @property int $points
 * @property int $balance_after
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $reason
 * @property int|null $course_id
 * @property string|null $dedupe_key
 * @property CarbonInterface $awarded_at
 */
final class PointTransaction extends Model
{
    protected $fillable = [
        'user_id', 'rule_id', 'points', 'balance_after',
        'source_type', 'source_id', 'reason', 'course_id', 'dedupe_key', 'awarded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'balance_after' => 'integer',
            'awarded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GamificationRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(GamificationRule::class, 'rule_id');
    }
}
