<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Engagement\ReviewController;
use Illuminate\Support\Facades\Route;

/*
 * Engagement. Members-only like the rest of the catalogue — there is no
 * anonymous surface (§16), so course reviews are read by people signed in to
 * the academy rather than by the open internet.
 */
Route::middleware(['auth:sanctum', 'tenant', 'subscription'])->group(function (): void {
    Route::get('courses/{course}/reviews', [ReviewController::class, 'index'])
        ->name('reviews.index');

    /*
     * One review per learner per course, so this writes OR replaces. There is
     * deliberately no PATCH /reviews/{id} to keep in step with it.
     */
    Route::post('courses/{course}/reviews', [ReviewController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('reviews.store');

    Route::delete('reviews/{review}', [ReviewController::class, 'destroy'])
        ->name('reviews.destroy');

    Route::post('reviews/{review}/reply', [ReviewController::class, 'reply'])
        ->name('reviews.reply');

    Route::get('admin/reviews', [ReviewController::class, 'queue'])
        ->name('admin.reviews.queue');
    Route::post('admin/reviews/{review}/moderate', [ReviewController::class, 'moderate'])
        ->name('admin.reviews.moderate');
});
