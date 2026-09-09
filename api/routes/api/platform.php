<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\PlanController;
use App\Http\Controllers\Api\V1\Admin\TenantController;
use Illuminate\Support\Facades\Route;

/*
 * The platform operator's surface.
 *
 * Central-DB only, and deliberately OUTSIDE the `tenant` middleware: these
 * routes are about academies rather than inside one, and a suspended or
 * lapsed academy is exactly the one an operator needs to reach. Putting them
 * behind `tenant` would lock the operator out of the academies that need
 * attention — and behind `subscription`, out of the very action that fixes a
 * lapsed one.
 */

Route::middleware(['auth:sanctum', 'super_admin'])
    ->prefix('admin')->name('admin.')
    ->group(function (): void {
        // What an academy can be put ON. Both writes that take a plan speak in
        // its slug, so the operator needs the list to pick from.
        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');

        Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');

        // Static segment before the dynamic one, so `leave` is never read as
        // an academy id.
        Route::post('tenants/leave', [TenantController::class, 'leave'])->name('tenants.leave');
        Route::post('tenants/{tenant}/enter', [TenantController::class, 'enter'])->name('tenants.enter');

        Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
        Route::patch('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
        Route::put('tenants/{tenant}/plan', [TenantController::class, 'assignPlan'])
            ->name('tenants.plan');
    });
