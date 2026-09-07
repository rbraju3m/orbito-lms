<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Every route lives under /api/v1. Domain route files are included here as
| each phase lands; see docs/API.md for the full planned surface.
|
| Phase 2 ships only the health endpoint — enough to prove the envelope,
| the error handler, the request id, and every infrastructure dependency.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');

    // Phase 3+: require_once __DIR__.'/api/auth.php'; etc.
});
