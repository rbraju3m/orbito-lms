<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\PublicSite\AcademyController;
use App\Http\Controllers\Api\V1\PublicSite\CourseController;
use App\Http\Controllers\Api\V1\PublicSite\WebinarController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The public site
|--------------------------------------------------------------------------
| The ONLY anonymous surface in the product. Everything else resolves its
| academy from the authenticated user (§ Multi-tenancy); these routes carry
| the academy's SLUG in the path and `tenant.public` opens it.
|
| NO `auth:sanctum` and NO `tenant`. A route added here is a decision that a
| stranger seeing every field it emits is the intended outcome — the middleware
| docblock states the argument in full. Anything viewer-scoped, anything
| unpublished, and anything that WRITES belongs elsewhere: lead capture and
| guest registration will need their own abuse story before they land here.
|
| Throttled per IP rather than per academy: a bucket shared by everybody
| reading one academy's site would let a script take that site down.
*/

Route::prefix('public/{academy}')
    ->middleware(['throttle:public', 'tenant.public'])
    ->name('public.')
    ->group(function (): void {
        Route::get('/', AcademyController::class)->name('academy');

        Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
        Route::get('courses/{slug}', [CourseController::class, 'show'])->name('courses.show');

        Route::get('webinars', [WebinarController::class, 'index'])->name('webinars.index');
        Route::get('webinars/{slug}', [WebinarController::class, 'show'])->name('webinars.show');
    });
