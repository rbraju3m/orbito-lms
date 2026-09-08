<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Gamification\AchievementsController;
use App\Http\Controllers\Api\V1\Gamification\LeaderboardController;
use Illuminate\Support\Facades\Route;

/*
 * Points, badges, streaks and boards.
 *
 * Everything here is keyed on the caller or on a snapshot, so there is no
 * policy: `GET /achievements` is your own and there is deliberately no
 * endpoint for reading somebody else's. The leaderboard is the only place
 * another person's points appear, and only for people who did not opt out.
 */
Route::middleware(['auth:sanctum', 'tenant', 'subscription'])->group(function (): void {
    Route::get('achievements', [AchievementsController::class, 'show'])
        ->name('achievements.show');

    /*
     * Opting out of the boards. A write, so `subscription` gates it — which
     * is the one place that stings slightly: a lapsed academy's learners
     * cannot change the setting. It is a privacy preference, not data, and
     * the read still tells them what it is.
     */
    Route::patch('achievements/ranking', [AchievementsController::class, 'updateRanking'])
        ->name('achievements.ranking');

    Route::get('leaderboard', [LeaderboardController::class, 'index'])
        ->name('leaderboard.index');
    Route::get('courses/{course}/leaderboard', [LeaderboardController::class, 'course'])
        ->name('leaderboard.course');
});
