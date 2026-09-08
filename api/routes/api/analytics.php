<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Analytics\AnalyticsController;
use App\Http\Controllers\Api\V1\Analytics\AnalyticsExportController;
use App\Http\Controllers\Api\V1\Analytics\TrackController;
use Illuminate\Support\Facades\Route;

/*
 * Analytics ingest.
 *
 * `subscription` is DELIBERATELY absent. It gates writes, and this is a write
 * in HTTP terms — but an observation in domain terms: it creates nothing the
 * academy owns and unlocks nothing. A lapsed academy still reads and exports
 * everything (§ Multi-tenancy), and its pages firing view beacons into a 402 would be
 * noise in a console at best and a broken retry loop at worst.
 *
 * Rate-limited per user, or per IP when there is none — a beacon endpoint is
 * the easiest thing in an API to point a script at.
 */
Route::middleware(['auth:sanctum', 'tenant', 'throttle:analytics'])->group(function (): void {
    Route::post('analytics/track', TrackController::class)->name('analytics.track');
});

/*
 * The dashboards. Reads only, so `subscription` costs nothing here either —
 * a lapsed academy still sees and EXPORTS its own numbers, which is the whole
 * point of gating writes rather than reads (§ Multi-tenancy).
 *
 * Every one of these reads rollups. There is deliberately no endpoint over
 * `analytics_events`: the log is a write path and a rebuild source, and
 * exposing it would let one screen ask a question the rollups cannot answer —
 * which is two definitions of one metric, one release later.
 */
Route::middleware(['auth:sanctum', 'tenant', 'subscription'])->group(function (): void {
    Route::get('analytics/overview', [AnalyticsController::class, 'overview'])
        ->name('analytics.overview');

    Route::get('analytics/courses/{course}', [AnalyticsController::class, 'course'])
        ->name('analytics.course');
    Route::get('analytics/courses/{course}/funnel', [AnalyticsController::class, 'funnel'])
        ->name('analytics.course.funnel');

    Route::get('analytics/instructors/{user}', [AnalyticsController::class, 'instructor'])
        ->name('analytics.instructor');

    /*
     * CSV. The only responses in the API that are not `{data:…}` — a
     * spreadsheet cannot unwrap an envelope.
     */
    Route::get('analytics/export/platform', [AnalyticsExportController::class, 'platform'])
        ->name('analytics.export.platform');
    Route::get('analytics/export/courses', [AnalyticsExportController::class, 'courses'])
        ->name('analytics.export.courses');
    Route::get('analytics/courses/{course}/export', [AnalyticsExportController::class, 'funnel'])
        ->name('analytics.export.funnel');
});
