<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Engagement\AnnouncementController;
use App\Http\Controllers\Api\V1\Engagement\DiscussionController;
use App\Http\Controllers\Api\V1\Engagement\ReviewController;
use App\Http\Controllers\Api\V1\Engagement\WishlistController;
use Illuminate\Support\Facades\Route;

/*
 * Engagement. Members-only like the rest of the catalogue — there is no
 * anonymous surface (§ Multi-tenancy), so course reviews are read by people signed in to
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

    /* -------------------------------------------------------------- Q&A */

    Route::get('courses/{course}/discussions', [DiscussionController::class, 'index'])
        ->name('discussions.index');
    Route::post('courses/{course}/discussions', [DiscussionController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('discussions.store');

    Route::get('discussions/{discussion}', [DiscussionController::class, 'show'])
        ->name('discussions.show');
    Route::post('discussions/{discussion}/replies', [DiscussionController::class, 'reply'])
        ->middleware('throttle:30,1')
        ->name('discussions.reply');

    /*
     * Accepting an answer. The ASKER or course staff — not any moderator:
     * choosing which reply answered your question is a judgement only you and
     * the people teaching the course can make.
     */
    Route::post('discussions/{discussion}/accept', [DiscussionController::class, 'accept'])
        ->name('discussions.accept');

    // Hiding, unhiding and pinning. One endpoint, one capability.
    Route::patch('discussions/{discussion}/moderate', [DiscussionController::class, 'moderate'])
        ->name('discussions.moderate');

    Route::delete('discussion-replies/{reply}', [DiscussionController::class, 'destroyReply'])
        ->name('discussions.replies.destroy');

    /* ---------------------------------------------------- Announcements */

    Route::get('courses/{course}/announcements', [AnnouncementController::class, 'index'])
        ->name('announcements.index');
    Route::post('courses/{course}/announcements', [AnnouncementController::class, 'store'])
        ->name('announcements.store');
    Route::patch('announcements/{announcement}', [AnnouncementController::class, 'update'])
        ->name('announcements.update');
    Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])
        ->name('announcements.destroy');

    /*
     * Publishing is its own endpoint, not a field on the update. Saving a
     * draft and sending it to a thousand people are different acts and should
     * not be one careless boolean apart.
     */
    Route::post('announcements/{announcement}/publish', [AnnouncementController::class, 'publish'])
        ->name('announcements.publish');
    Route::delete('announcements/{announcement}/publish', [AnnouncementController::class, 'unpublish'])
        ->name('announcements.unpublish');

    /* -------------------------------------------------------- Wishlist */

    Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('wishlist/{course}', [WishlistController::class, 'store'])->name('wishlist.store');
    Route::delete('wishlist/{course}', [WishlistController::class, 'destroy'])
        ->name('wishlist.destroy');

    Route::get('admin/reviews', [ReviewController::class, 'queue'])
        ->name('admin.reviews.queue');
    Route::post('admin/reviews/{review}/moderate', [ReviewController::class, 'moderate'])
        ->name('admin.reviews.moderate');
});
