<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Studio\CoursePrerequisiteController;
use App\Http\Controllers\Api\V1\Studio\EnrollmentController;
use Illuminate\Support\Facades\Route;

/*
 * Enrollment management, the staff side. The learner's own enrollment lives in
 * learn.php — `POST /courses/{course}/enroll` and `GET /learn/courses`.
 */

Route::middleware(['auth:sanctum', 'tenant', 'subscription'])->prefix('studio')->name('studio.')->group(function (): void {
    Route::get('courses/{course}/students', [EnrollmentController::class, 'index'])
        ->name('students.index');

    Route::post('courses/{course}/enrollments', [EnrollmentController::class, 'store'])
        ->name('enrollments.store');

    // Bounded and synchronous, so it answers per row. Throttled because it is
    // the one studio endpoint whose cost scales with the request body.
    Route::post('courses/{course}/enrollments/bulk', [EnrollmentController::class, 'bulk'])
        ->middleware('throttle:6,1')
        ->name('enrollments.bulk');

    Route::patch('enrollments/{enrollment}', [EnrollmentController::class, 'update'])
        ->name('enrollments.update');

    Route::put('courses/{course}/prerequisites', [CoursePrerequisiteController::class, 'update'])
        ->name('prerequisites.update');
});
