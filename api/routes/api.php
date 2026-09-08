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
*/

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');

    require __DIR__.'/api/auth.php';
    require __DIR__.'/api/identity.php';
    require __DIR__.'/api/catalog.php';
    require __DIR__.'/api/curriculum.php';
    require __DIR__.'/api/learn.php';
    require __DIR__.'/api/assessment.php';
    require __DIR__.'/api/enrollment.php';
    require __DIR__.'/api/commerce.php';
    require __DIR__.'/api/certification.php';
    require __DIR__.'/api/engagement.php';
    require __DIR__.'/api/platform.php';
});
