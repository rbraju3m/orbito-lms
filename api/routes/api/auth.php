<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

/*
| Authentication. See docs/API.md §3.
|
| The `auth` throttle limits per IP *and* per email address, so neither a single
| host nor a single account can be hammered.
*/

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('register', RegisterController::class)->name('register');
        Route::post('login', LoginController::class)->name('login');
        Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->name('password.forgot');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');
    });

    // `signed` validates the emailed link; the action additionally checks the
    // hash matches the address it was minted for, so a valid signature for one
    // user cannot verify another.
    Route::post('email/verify', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed:relative', 'throttle:auth'])
        ->name('email.verify');

    /*
     * Central only. Signing out and re-sending a verification email touch
     * nothing inside an academy — and a learner whose academy has been
     * suspended must still be able to sign out, which `tenant` would refuse.
     */
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', LogoutController::class)->name('logout');
        Route::post('email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:auth')
            ->name('email.resend');
    });

    // `me` returns the caller's permission keys, which live in the academy's
    // schema, so this one needs the tenant open.
    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::get('me', MeController::class)->name('me');
    });
});
