<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Certification\CertificateController;
use App\Http\Controllers\Api\V1\Certification\CertificateTemplateController;
use App\Http\Controllers\Api\V1\Certification\CertificateVerificationController;
use Illuminate\Support\Facades\Route;

/*
 * THE PUBLIC VERIFICATION PAGE. The second route with no authenticated user.
 *
 * A hiring manager holding a printed certificate has no account and never
 * will, so this cannot sit behind `auth:sanctum` — which means it cannot
 * resolve an academy the usual way either. The tenant is in the PATH
 * (`tenant.path`) and the 32-character token is the credential.
 *
 * The academy id rather than the slug, because this URL gets PRINTED: an
 * academy renaming itself must not invalidate paper already in the world.
 *
 * No `subscription` gate. A lapsed academy's certificates must still verify —
 * the qualification was earned, and a billing lapse is between the platform
 * and the academy, not something to take out on a former student's CV.
 *
 * Throttled, because the token is the only thing between a stranger and a
 * person's name, and an unthrottled lookup is a guessing machine.
 */
Route::get('verify/{tenant}/{token}', CertificateVerificationController::class)
    ->middleware(['throttle:30,1', 'tenant.path'])
    ->name('certificates.verify');

Route::middleware(['auth:sanctum', 'tenant', 'subscription'])->group(function (): void {
    Route::get('certificates', [CertificateController::class, 'index'])
        ->name('certificates.index');
    Route::get('certificates/{certificate}', [CertificateController::class, 'show'])
        ->name('certificates.show');

    /*
     * Returns a short-lived signed URL, not the bytes (ADR-09). A GET rather
     * than a POST because it changes nothing — and so it keeps working for a
     * lapsed academy, which is the point of the 402 gating writes only.
     */
    Route::get('certificates/{certificate}/download', [CertificateController::class, 'download'])
        ->name('certificates.download');

    // Its own capability, not `view.any`: revoking makes a public page say
    // somebody's qualification was taken back.
    Route::post('certificates/{certificate}/revoke', [CertificateController::class, 'revoke'])
        ->name('certificates.revoke');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('certificate-templates', [CertificateTemplateController::class, 'index'])
            ->name('certificate-templates.index');
        Route::post('certificate-templates', [CertificateTemplateController::class, 'store'])
            ->name('certificate-templates.store');
        Route::patch('certificate-templates/{template}', [CertificateTemplateController::class, 'update'])
            ->name('certificate-templates.update');
        Route::delete('certificate-templates/{template}', [CertificateTemplateController::class, 'destroy'])
            ->name('certificate-templates.destroy');
    });
});
