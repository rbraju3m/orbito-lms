<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Assessment\AttemptController;
use App\Http\Controllers\Api\V1\Assessment\GradingController;
use App\Http\Controllers\Api\V1\Assessment\QuizBuilderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {

    /* -------- Authoring (returns correct answers; course-scoped authz) -------- */
    Route::prefix('studio')->name('studio.')->group(function (): void {
        Route::get('items/{item}/quiz', [QuizBuilderController::class, 'show'])->name('quiz.show');
        Route::patch('items/{item}/quiz', [QuizBuilderController::class, 'update'])->name('quiz.update');

        Route::post('items/{item}/quiz/questions', [QuizBuilderController::class, 'storeQuestion'])
            ->name('quiz.questions.store');
        Route::patch('items/{item}/quiz/questions/order', [QuizBuilderController::class, 'reorderQuestions'])
            ->name('quiz.questions.order');
        Route::patch('items/{item}/quiz/questions/{question}', [QuizBuilderController::class, 'updateQuestion'])
            ->withoutScopedBindings()
            ->name('quiz.questions.update');
        Route::delete('items/{item}/quiz/questions/{question}', [QuizBuilderController::class, 'destroyQuestion'])
            ->withoutScopedBindings()
            ->name('quiz.questions.destroy');

        /* -------- Grading queue -------- */
        Route::get('courses/{course}/grading', [GradingController::class, 'queue'])->name('grading.queue');
        Route::get('grading/{attempt}', [GradingController::class, 'show'])->name('grading.show');
        Route::post('grading/{attempt}', [GradingController::class, 'grade'])->name('grading.grade');
    });

    /*
     * The runner. Questions here are served WITHOUT correct answers, and the
     * countdown is the server's (ADR-06).
     */
    Route::prefix('learn')->name('learn.')->group(function (): void {
        Route::get('items/{item}/quiz/attempts', [AttemptController::class, 'index'])
            ->name('attempts.index');
        Route::post('items/{item}/quiz/attempts', [AttemptController::class, 'store'])
            ->name('attempts.store');

        Route::get('quiz-attempts/{attempt}', [AttemptController::class, 'show'])->name('attempts.show');
        Route::patch('quiz-attempts/{attempt}/answers', [AttemptController::class, 'saveAnswer'])
            ->name('attempts.answer');
        Route::post('quiz-attempts/{attempt}/submit', [AttemptController::class, 'submit'])
            ->name('attempts.submit');
        Route::get('quiz-attempts/{attempt}/result', [AttemptController::class, 'result'])
            ->name('attempts.result');
    });
});
