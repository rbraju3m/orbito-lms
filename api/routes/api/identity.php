<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AcademyController;
use App\Http\Controllers\Api\V1\Admin\InstructorController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Identity\InstructorApplicationController;
use App\Http\Controllers\Api\V1\Identity\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant', 'subscription'])->group(function (): void {

    /* -------- The caller's own account -------- */
    Route::prefix('account')->name('account.')->group(function (): void {
        Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('password', [ProfileController::class, 'changePassword'])
            ->middleware('throttle:auth')
            ->name('password.change');

        Route::get('instructor-application', [InstructorApplicationController::class, 'show'])
            ->name('instructor.show');
        Route::post('instructor-application', [InstructorApplicationController::class, 'store'])
            ->name('instructor.apply');
    });

    /* -------- Administration -------- */
    Route::prefix('admin')->name('admin.')->group(function (): void {
        // The academy administering ITSELF. No id in the path: the caller's
        // own academy is the only one they may touch, and the `tenant`
        // middleware has already resolved it.
        Route::get('academy', [AcademyController::class, 'show'])->name('academy.show');
        Route::patch('academy', [AcademyController::class, 'update'])->name('academy.update');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::post('users/{user}/suspension', [UserController::class, 'suspend'])->name('users.suspend');

        Route::get('users/{user}/roles', [RoleController::class, 'assignments'])->name('users.roles.index');
        Route::post('users/{user}/roles', [RoleController::class, 'assign'])->name('users.roles.assign');
        // withoutScopedBindings: the role is an independent lookup, not a child
        // of the user, so Laravel must not try to resolve it via $user->roles().
        Route::delete('users/{user}/roles/{role:key}', [RoleController::class, 'revoke'])
            ->withoutScopedBindings()
            ->name('users.roles.revoke');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions.index');

        Route::get('instructors', [InstructorController::class, 'index'])->name('instructors.index');
        Route::post('instructors/{instructorProfile}/review', [InstructorController::class, 'review'])
            ->name('instructors.review');
    });
});
