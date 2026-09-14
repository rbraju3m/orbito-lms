<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Content\LeadController;
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
    });

Route::middleware(['auth:sanctum', 'tenant', 'subscription'])
    ->prefix('admin')->name('admin.')->group(function (): void {
        Route::patch('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    });
