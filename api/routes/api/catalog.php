<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\CourseCatalogController;
use App\Http\Controllers\Api\V1\Media\MediaController;
use App\Http\Controllers\Api\V1\Studio\CourseController;
use App\Http\Controllers\Api\V1\Studio\CourseInstructorController;
use App\Http\Controllers\Api\V1\Studio\CourseSettingsController;
use App\Http\Controllers\Api\V1\Studio\CourseStatusController;
use Illuminate\Support\Facades\Route;

/* -------- Public catalogue -------- */
Route::get('courses', [CourseCatalogController::class, 'index'])->name('courses.index');
Route::get('courses/{slug}', [CourseCatalogController::class, 'show'])->name('courses.show');
Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
Route::get('categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
Route::get('tags', [CategoryController::class, 'tags'])->name('tags.index');

/*
 * Signed download for private media. The signature IS the credential, which is
 * why this sits outside auth:sanctum — a <video> or <img> tag cannot send a
 * bearer token. Access was checked when the URL was minted (ADR-09).
 */
Route::get('media/{media}/download', [MediaController::class, 'download'])
    ->middleware('signed')
    ->name('media.download');

Route::middleware('auth:sanctum')->group(function (): void {

    /* -------- Media -------- */
    Route::post('media', [MediaController::class, 'store'])->name('media.store');
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
