<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Learn\EnrollmentController;
use App\Http\Controllers\Api\V1\Learn\LearnController;
use App\Http\Controllers\Api\V1\Learn\NoteController;
use App\Http\Controllers\Api\V1\Learn\ProgressController;
use Illuminate\Support\Facades\Route;

/*
 * The learner surface. Every content route resolves access through the single
 * CourseAccess service (ADR-03) — never an ad-hoc enrollment check.
 *
 * `subscription` gates WRITES here as everywhere else, which means a lapsed
 * academy's learners can still read every lesson they are enrolled in but
 * cannot record progress or hand work in. That is a deliberate consequence of
 * one platform-wide rule rather than a decision about learners, and it is the
 * sharpest edge of the read-only model — worth revisiting if academies start
 * lapsing with live cohorts.
 */

Route::prefix('learn')->name('learn.')->middleware(['auth:sanctum', 'tenant', 'subscription'])->group(function (): void {
    /*
     * These two once served anonymous callers a preview and a locked outline.
     * They cannot any more: with tenancy resolved from the authenticated user,
     * an anonymous request belongs to no academy. Preview items still work —
     * they are now "try before you ENROL" rather than "try before you sign up".
     */
    Route::get('courses/{course}', [LearnController::class, 'bootstrap'])->name('courses.show');
    Route::get('items/{item}', [LearnController::class, 'item'])->name('items.show');

    Route::get('continue', [EnrollmentController::class, 'continueLearning'])->name('continue');
    Route::get('courses', [EnrollmentController::class, 'myCourses'])->name('courses.index');

    Route::post('items/{item}/complete', [ProgressController::class, 'complete'])
        ->name('items.complete');
    Route::delete('items/{item}/complete', [ProgressController::class, 'uncomplete'])
        ->name('items.uncomplete');

    // The video heartbeat fires every 15s per item; it must never be able
    // to cost more than that.
    Route::post('items/{item}/watch', [ProgressController::class, 'watch'])
        ->middleware('throttle:watch')
        ->name('items.watch');

    Route::post('courses/{course}/complete', [ProgressController::class, 'completeCourse'])
        ->name('courses.complete');
    Route::post('courses/{course}/reset-progress', [ProgressController::class, 'reset'])
        ->name('courses.reset');

    Route::get('items/{item}/notes', [NoteController::class, 'index'])->name('notes.index');
    Route::post('items/{item}/notes', [NoteController::class, 'store'])->name('notes.store');
    Route::delete('notes/{note}', [NoteController::class, 'destroy'])->name('notes.destroy');
});

Route::middleware(['auth:sanctum', 'tenant', 'subscription'])->group(function (): void {
    Route::post('courses/{course}/enroll', [EnrollmentController::class, 'store'])->name('courses.enroll');
});
