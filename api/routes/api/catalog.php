<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Catalog\BundleCatalogController;
use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\CourseCatalogController;
use App\Http\Controllers\Api\V1\Catalog\DownloadCatalogController;
use App\Http\Controllers\Api\V1\Media\MediaController;
use App\Http\Controllers\Api\V1\Studio\BundleController;
use App\Http\Controllers\Api\V1\Studio\BundleStatusController;
use App\Http\Controllers\Api\V1\Studio\CourseController;
use App\Http\Controllers\Api\V1\Studio\CourseInstructorController;
use App\Http\Controllers\Api\V1\Studio\CourseSettingsController;
use App\Http\Controllers\Api\V1\Studio\CourseStatusController;
use App\Http\Controllers\Api\V1\Studio\DownloadController;
use App\Http\Controllers\Api\V1\Studio\DownloadStatusController;
use App\Http\Controllers\Api\V1\Studio\PricingController;
use Illuminate\Support\Facades\Route;

/*
 * Signed download for private media. The signature IS the credential, which is
 * why this sits outside auth:sanctum — a <video> or <img> tag cannot send a
 * bearer token. Access was checked when the URL was minted (ADR-09).
 *
 * It therefore has no authenticated user to read a tenant from, so the academy
 * travels INSIDE the signed payload and `tenant.signed` opens it. The signature
 * covers that parameter, so it cannot be swapped for another academy's.
 */
Route::get('media/{media}/download', [MediaController::class, 'download'])
    ->middleware(['signed', 'tenant.signed'])
    ->name('media.download');

/*
 * The catalogue is MEMBERS-ONLY.
 *
 * Tenancy is resolved from the authenticated user, so an anonymous request
 * cannot be attributed to an academy at all — there is no host, path or token
 * to read one from. Course listings, course pages, categories and tags are
 * therefore behind auth, and a signed-out visitor gets a login screen rather
 * than a storefront.
 */
Route::middleware(['auth:sanctum', 'tenant', 'subscription'])->group(function (): void {

    /* -------- Catalogue -------- */
    Route::get('courses', [CourseCatalogController::class, 'index'])->name('courses.index');
    Route::get('courses/{slug}', [CourseCatalogController::class, 'show'])->name('courses.show');
    /*
     * Bundles, as a buyer sees them. `bundles` is declared BEFORE any
     * `{slug}` route so it is never read as a course slug (§ Phase 12).
     */
    Route::get('bundles', [BundleCatalogController::class, 'index'])->name('bundles.index');
    Route::get('bundles/{slug}', [BundleCatalogController::class, 'show'])->name('bundles.show');

    /*
     * Digital downloads (P16). `mine` is declared BEFORE `{slug}` so it is
     * never read as a download called "mine" (§ Phase 12).
     */
    Route::get('downloads', [DownloadCatalogController::class, 'index'])->name('downloads.index');
    Route::get('downloads/mine', [DownloadCatalogController::class, 'mine'])->name('downloads.mine');
    Route::get('downloads/{slug}', [DownloadCatalogController::class, 'show'])->name('downloads.show');
    Route::post('downloads/{slug}/claim', [DownloadCatalogController::class, 'claim'])->name('downloads.claim');
    // A GET, so a lapsed academy's buyers keep their files — 402 gates writes.
    Route::get('downloads/{slug}/file', [DownloadCatalogController::class, 'file'])->name('downloads.file');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
    Route::get('tags', [CategoryController::class, 'tags'])->name('tags.index');

    /* -------- Media -------- */
    Route::post('media', [MediaController::class, 'store'])
        ->middleware('throttle:uploads')
        ->name('media.store');
    Route::get('media/{media}/url', [MediaController::class, 'url'])->name('media.url');
    Route::delete('media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');

    /* -------- Studio (authoring) -------- */
    Route::prefix('studio')->name('studio.')->group(function (): void {
        Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
        Route::post('courses', [CourseController::class, 'store'])->name('courses.store');
        Route::get('courses/{course}', [CourseController::class, 'show'])->name('courses.show');
        Route::patch('courses/{course}', [CourseController::class, 'update'])->name('courses.update');
        Route::delete('courses/{course}', [CourseController::class, 'destroy'])->name('courses.destroy');

        Route::patch('courses/{course}/settings', [CourseSettingsController::class, 'update'])
            ->name('courses.settings.update');

        /*
         * What a course costs. Its own permission (`course.price.*`), because
         * what a course EARNS is a different decision from what it says — an
         * academy can let a TA fix a typo without letting them halve the
         * price.
         */
        Route::put('courses/{course}/price', [PricingController::class, 'course'])
            ->name('courses.price');

        /* -------- Bundles -------- */
        Route::get('bundles', [BundleController::class, 'index'])->name('bundles.index');
        Route::post('bundles', [BundleController::class, 'store'])->name('bundles.store');
        Route::get('bundles/{bundle}', [BundleController::class, 'show'])->name('bundles.show');
        Route::patch('bundles/{bundle}', [BundleController::class, 'update'])->name('bundles.update');
        Route::delete('bundles/{bundle}', [BundleController::class, 'destroy'])->name('bundles.destroy');

        Route::put('bundles/{bundle}/price', [PricingController::class, 'bundle'])
            ->name('bundles.price');

        // Lifecycle as sub-resources, not a verb in a query string.
        Route::post('bundles/{bundle}/publish', [BundleStatusController::class, 'publish'])
            ->name('bundles.publish');
        Route::post('bundles/{bundle}/unpublish', [BundleStatusController::class, 'unpublish'])
            ->name('bundles.unpublish');
        Route::post('bundles/{bundle}/archive', [BundleStatusController::class, 'archive'])
            ->name('bundles.archive');

        /* -------- Downloads -------- */
        Route::get('downloads', [DownloadController::class, 'index'])->name('downloads.index');
        Route::post('downloads', [DownloadController::class, 'store'])->name('downloads.store');
        Route::get('downloads/{download}', [DownloadController::class, 'show'])->name('downloads.show');
        Route::patch('downloads/{download}', [DownloadController::class, 'update'])->name('downloads.update');
        Route::delete('downloads/{download}', [DownloadController::class, 'destroy'])->name('downloads.destroy');

        Route::put('downloads/{download}/price', [PricingController::class, 'download'])
            ->name('downloads.price');

        Route::post('downloads/{download}/publish', [DownloadStatusController::class, 'publish'])
            ->name('downloads.publish');
        Route::post('downloads/{download}/unpublish', [DownloadStatusController::class, 'unpublish'])
            ->name('downloads.unpublish');
        Route::post('downloads/{download}/archive', [DownloadStatusController::class, 'archive'])
            ->name('downloads.archive');

        // Lifecycle transitions are sub-resources, never a verb in a query string.
        Route::post('courses/{course}/publish', [CourseStatusController::class, 'publish'])
            ->name('courses.publish');
        Route::post('courses/{course}/unpublish', [CourseStatusController::class, 'unpublish'])
            ->name('courses.unpublish');
        Route::post('courses/{course}/submit-review', [CourseStatusController::class, 'submitForReview'])
            ->name('courses.submit-review');
        Route::post('courses/{course}/approve-review', [CourseStatusController::class, 'approveReview'])
            ->name('courses.approve-review');
        Route::post('courses/{course}/reject-review', [CourseStatusController::class, 'rejectReview'])
            ->name('courses.reject-review');
        Route::post('courses/{course}/archive', [CourseStatusController::class, 'archive'])
            ->name('courses.archive');

        Route::post('courses/{course}/instructors', [CourseInstructorController::class, 'store'])
            ->name('courses.instructors.store');
        Route::delete('courses/{course}/instructors/{user}', [CourseInstructorController::class, 'destroy'])
            ->withoutScopedBindings()
            ->name('courses.instructors.destroy');
    });
});
