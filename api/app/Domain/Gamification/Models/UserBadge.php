<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A badge somebody holds.
 *
 * Awarded once, and the unique index on (user_id, badge_id) is the guarantee —
 * the awarding action catches the constraint rather than checking first,
 * because two triggers arriving together both find nothing and both insert.
 *
 * @property int $id
 * @property int $user_id
 * @property int $badge_id
 * @property CarbonInterface $awarded_at
 * @property string|null $source_type
 * @property int|null $source_id
 */
final class UserBadge extends Model
{
    protected $fillable = ['user_id', 'badge_id', 'awarded_at', 'source_type', 'source_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['awarded_at' => 'datetime'];
    }

    /** @return BelongsTo<Badge, $this> */
    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }
}
