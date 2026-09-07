<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Curriculum\CurriculumController;
use App\Http\Controllers\Api\V1\Curriculum\ItemController;
use App\Http\Controllers\Api\V1\Curriculum\SectionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant', 'subscription'])->prefix('studio')->name('studio.')->group(function (): void {
    Route::get('courses/{course}/curriculum', [CurriculumController::class, 'show'])
        ->name('curriculum.show');

    // The single write path for `position` (ADR-01). Takes the whole tree.
    Route::patch('courses/{course}/curriculum/order', [CurriculumController::class, 'reorder'])
        ->name('curriculum.reorder');

    Route::post('courses/{course}/sections', [SectionController::class, 'store'])
        ->name('sections.store');
    Route::patch('sections/{section}', [SectionController::class, 'update'])->name('sections.update');
    Route::delete('sections/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');
    Route::post('sections/{section}/duplicate', [SectionController::class, 'duplicate'])
        ->name('sections.duplicate');

    Route::post('courses/{course}/items', [ItemController::class, 'store'])->name('items.store');
    Route::get('items/{item}', [ItemController::class, 'show'])->name('items.show');
    Route::patch('items/{item}', [ItemController::class, 'update'])->name('items.update');
    Route::delete('items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');
    Route::post('items/{item}/duplicate', [ItemController::class, 'duplicate'])->name('items.duplicate');
    Route::patch('items/{item}/lesson', [ItemController::class, 'updateLesson'])->name('items.lesson');
});
