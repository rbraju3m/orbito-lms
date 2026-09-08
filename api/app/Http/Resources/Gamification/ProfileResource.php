<?php

declare(strict_types=1);

namespace App\Http\Resources\Gamification;

use App\Domain\Gamification\Models\GamificationProfile;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Somebody's own standing.
 *
 * @mixin GamificationProfile
 */
final class ProfileResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'points_total' => $this->points_total,
            'current_streak_days' => $this->current_streak_days,
            'longest_streak_days' => $this->longest_streak_days,
            'last_active_date' => $this->last_active_date?->toDateString(),
            /*
             * Returned so the settings toggle renders from the server's
             * answer. Opting out stops the publication, never the points —
             * the balance and the badges above are still theirs.
             */
            'is_ranked' => $this->is_ranked,
        ];
    }
}
