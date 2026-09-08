<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Gamification;

use App\Domain\Gamification\Models\GamificationProfile;
use App\Domain\Gamification\Queries\AchievementsQuery;
use App\Http\Resources\Gamification\BadgeResource;
use App\Http\Resources\Gamification\ProfileResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A learner's own points, badges and streak.
 *
 * No policy: every query is keyed on the caller's own id, so there is nobody
 * else's standing to authorize against — the same reasoning as the wishlist
 * and the notification inbox. There is deliberately no endpoint for reading
 * somebody ELSE's profile; the leaderboard is the only place another person's
 * points appear, and only for people who did not opt out.
 */
final class AchievementsController
{
    public function show(Request $request, AchievementsQuery $query): JsonResponse
    {
        $result = $query->forUser($request->user()->id);

        return ApiResponse::ok([
            'profile' => ProfileResource::make($result['profile'])->resolve($request),
            'badges' => $result['badges']
                ->map(fn ($badge) => BadgeResource::make(
                    $badge,
                    isset($result['held'][$badge->id]),
                    $result['held'][$badge->id] ?? null,
                )->resolve($request))
                ->all(),
            'recent' => $result['recent']->map(fn ($transaction): array => [
                'points' => $transaction->points,
                'reason' => $transaction->reason,
                'awarded_at' => $transaction->awarded_at->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Opting in or out of the leaderboards.
     *
     * It stops the PUBLICATION and nothing else — points keep accruing, badges
     * keep being awarded, the streak keeps counting. Somebody who does not
     * want their study habits ranked in front of classmates should not have to
     * give up the rest to say so.
     */
    public function updateRanking(Request $request): JsonResponse
    {
        $validated = $request->validate(['is_ranked' => ['required', 'boolean']]);
        $userId = $request->user()->id;

        GamificationProfile::query()->updateOrCreate(
            ['user_id' => $userId],
            ['is_ranked' => (bool) $validated['is_ranked']],
        );

        return ApiResponse::ok(
            ProfileResource::make(GamificationProfile::query()->findOrFail($userId)),
        );
    }
}
