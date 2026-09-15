<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Content\LeadController;
use App\Http\Controllers\Api\V1\Content\PageController;
use App\Http\Controllers\Api\V1\Content\PageStatusController;
use App\Http\Controllers\Api\V1\Content\PostController;
use App\Http\Controllers\Api\V1\Content\PostStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Content — leads (P16)
|--------------------------------------------------------------------------
| The academy's side of the lead form. The stranger's side is in
| `public.php`, and deliberately shares nothing with this file.
*/

/*
 * Reads, the export and ERASURE sit outside the subscription gate. A lapsed
 * academy reads and exports everything (§ Multi-tenancy), and "delete my
 * details" is a request an academy has to be able to honour whether or not it
 * has paid us this month.
 */
Route::middleware(['auth:sanctum', 'tenant'])
    ->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
        // Every address in one file: throttled like the other exports.
        Route::get('leads/export', [LeadController::class, 'export'])
            ->middleware('throttle:10,1')
            ->name('leads.export');
        Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');

        // The blog, read by its authors — drafts and scheduled posts included.
        Route::get('posts', [PostController::class, 'index'])->name('posts.index');
        Route::get('posts/{post}', [PostController::class, 'show'])->name('posts.show');

        // Built pages, read by their builders (docs/PAGES.md).
        Route::get('pages', [PageController::class, 'index'])->name('pages.index');
        Route::get('pages/{page}', [PageController::class, 'show'])->name('pages.show');
    });

Route::middleware(['auth:sanctum', 'tenant', 'subscription'])
    ->prefix('admin')->name('admin.')->group(function (): void {
        Route::patch('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');

        /*
         * Writing the blog (docs/BLOG.md). Behind the subscription gate like
         * every other authoring write; publishing is a sub-resource, never a
         * field on the edit form.
         */
        Route::post('posts', [PostController::class, 'store'])->name('posts.store');
        Route::patch('posts/{post}', [PostController::class, 'update'])->name('posts.update');
        Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
        Route::post('posts/{post}/publish', [PostStatusController::class, 'publish'])->name('posts.publish');
        Route::post('posts/{post}/unpublish', [PostStatusController::class, 'unpublish'])->name('posts.unpublish');

        /*
         * Building pages (docs/PAGES.md). The block list is PUT whole — there
         * is no endpoint that moves one block (§ Patterns established in Phase 5).
         */
        Route::post('pages', [PageController::class, 'store'])->name('pages.store');
        Route::patch('pages/{page}', [PageController::class, 'update'])->name('pages.update');
        Route::put('pages/{page}/blocks', [PageController::class, 'blocks'])->name('pages.blocks');
        Route::delete('pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');
        Route::post('pages/{page}/publish', [PageStatusController::class, 'publish'])->name('pages.publish');
        Route::post('pages/{page}/unpublish', [PageStatusController::class, 'unpublish'])->name('pages.unpublish');
        Route::post('pages/{page}/home', [PageStatusController::class, 'makeHome'])->name('pages.home');
        Route::delete('pages/{page}/home', [PageStatusController::class, 'clearHome'])->name('pages.home.clear');
    });
