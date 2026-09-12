<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\LiveProviderController;
use App\Http\Controllers\Api\V1\Live\AttendanceController;
use App\Http\Controllers\Api\V1\Live\CalendarController;
use App\Http\Controllers\Api\V1\Live\CohortController;
use App\Http\Controllers\Api\V1\Live\LiveSessionController;
use App\Http\Controllers\Api\V1\Live\WebinarController;
use App\Http\Controllers\Api\V1\Studio\PricingController;
use Illuminate\Support\Facades\Route;

/*
 * Live learning. Members-only like everything else — a webinar's public
 * registration path waits for the marketing site in P16, when there will be
 * somewhere to register FROM (§ Multi-tenancy).
 */
Route::middleware(['auth:sanctum', 'tenant', 'subscription'])->group(function (): void {
    Route::get('calendar', CalendarController::class)->name('live.calendar');

    Route::get('courses/{course}/live-sessions', [LiveSessionController::class, 'index'])
        ->name('live.sessions.index');
    Route::post('courses/{course}/live-sessions', [LiveSessionController::class, 'store'])
        ->name('live.sessions.store');

    Route::patch('live-sessions/{session}', [LiveSessionController::class, 'update'])
        ->name('live.sessions.update');
    Route::delete('live-sessions/{session}', [LiveSessionController::class, 'destroy'])
        ->name('live.sessions.cancel');

    /*
     * Joining is a WRITE because following the link is the only attendance
     * signal every provider has in common — the manual one reports nothing.
     * Handing out the URL without recording it would leave every roster empty.
     */
    Route::post('live-sessions/{session}/join', [LiveSessionController::class, 'join'])
        ->middleware('throttle:30,1')
        ->name('live.sessions.join');
    Route::post('live-sessions/{session}/leave', [LiveSessionController::class, 'leave'])
        ->name('live.sessions.leave');

    Route::get('live-sessions/{session}/attendance', [AttendanceController::class, 'index'])
        ->name('live.attendance.index');
    Route::post('live-sessions/{session}/attendance', [AttendanceController::class, 'store'])
        ->name('live.attendance.store');

    /* ------------------------------------------------------------ cohorts */

    Route::get('courses/{course}/cohorts', [CohortController::class, 'index'])
        ->name('live.cohorts.index');
    Route::post('courses/{course}/cohorts', [CohortController::class, 'store'])
        ->name('live.cohorts.store');
    Route::patch('cohorts/{cohort}', [CohortController::class, 'update'])
        ->name('live.cohorts.update');
    Route::delete('cohorts/{cohort}', [CohortController::class, 'destroy'])
        ->name('live.cohorts.destroy');
    Route::post('cohorts/{cohort}/join', [CohortController::class, 'join'])
        ->name('live.cohorts.join');

    /* ----------------------------------------------------------- webinars */

    Route::get('webinars', [WebinarController::class, 'index'])->name('live.webinars.index');
    Route::get('webinars/{webinar}', [WebinarController::class, 'show'])->name('live.webinars.show');
    Route::post('webinars/{webinar}/register', [WebinarController::class, 'register'])
        ->name('live.webinars.register');
    Route::delete('webinars/{webinar}/register', [WebinarController::class, 'cancel'])
        ->name('live.webinars.cancel');

    /*
     * Authoring (`webinar.manage`). A webinar is CONTENT, so it lives beside
     * its own reads rather than under /admin — the academy's configuration is
     * what sits there. Creating one creates its session too; moving that
     * session is PATCH /live-sessions/{id}, which already resets the reminder
     * and tells the provider.
     */
    Route::post('webinars', [WebinarController::class, 'store'])->name('live.webinars.store');
    Route::patch('webinars/{webinar}', [WebinarController::class, 'update'])
        ->name('live.webinars.update');
    Route::post('webinars/{webinar}/status', [WebinarController::class, 'status'])
        ->name('live.webinars.status');
    Route::delete('webinars/{webinar}', [WebinarController::class, 'destroy'])
        ->name('live.webinars.destroy');

    /*
     * What a place costs. Beside the webinar rather than under /admin,
     * because a webinar is CONTENT and this is the same `webinar.manage`
     * decision as the rest of it — unlike a course, where what it EARNS is
     * its own permission held by different people.
     */
    Route::put('webinars/{webinar}/price', [PricingController::class, 'webinar'])
        ->name('live.webinars.price');
});

/*
 * The academy connecting its own meeting provider.
 *
 * Inside the subscription gate, like the payment gateways it mirrors: this is
 * the academy configuring how it teaches, not the action that fixes a lapsed
 * subscription.
 */
Route::middleware(['auth:sanctum', 'tenant', 'subscription'])
    ->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('live-providers', [LiveProviderController::class, 'index'])
            ->name('live.providers.index');
        Route::put('live-providers/{provider}', [LiveProviderController::class, 'update'])
            ->name('live.providers.update');
        Route::delete('live-providers/{provider}', [LiveProviderController::class, 'destroy'])
            ->name('live.providers.destroy');
    });
