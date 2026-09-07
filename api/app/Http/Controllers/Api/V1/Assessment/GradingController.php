<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Assessment;

use App\Domain\Assessment\Actions\GradeAnswerManually;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuizAttempt;
use App\Domain\Assessment\Queries\AttemptQuery;
use App\Domain\Catalog\Models\Course;
use App\Http\Resources\Assessment\AttemptResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The instructor's grading queue. A Teaching Assistant can reach this without
 * being able to author anything — that is the role's whole purpose.
 */
final class GradingController
{
    public function queue(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('view-quiz-attempts', $course);

        $perPage = min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );

        $attempts = QuizAttempt::query()
            ->where('course_id', $course->id)
            ->when(
                $request->string('status', 'awaiting_review')->value() !== 'all',
                fn ($q) => $q->where('status', $request->string('status', 'awaiting_review')->value()),
            )
            ->with(['user:id,uuid,name', 'item:id,uuid,title', 'quiz'])
            ->orderBy('submitted_at')
            ->paginate($perPage);

        return ApiResponse::ok($attempts->through(fn (QuizAttempt $attempt) => [
            'id' => $attempt->uuid,
            'status' => $attempt->status->value,
            'status_label' => $attempt->status->label(),
            'percent' => (float) $attempt->percent,
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'learner' => ['id' => $attempt->user?->uuid, 'name' => $attempt->user?->name],
            'item' => ['id' => $attempt->item?->uuid, 'title' => $attempt->item?->title],
        ]));
    }

    /** The full attempt, including correct answers — this reader may see them. */
    public function show(Request $request, QuizAttempt $attempt, AttemptQuery $query): JsonResponse
    {
        $attempt->loadMissing(['course', 'quiz', 'user']);
        Gate::authorize('view-quiz-attempts', $attempt->course);

        return ApiResponse::ok([
            'attempt' => AttemptResource::make($attempt)->resolve($request),
            'learner' => ['id' => $attempt->user?->uuid, 'name' => $attempt->user?->name],
            'review' => $query->review($attempt, revealAnswers: true),
        ]);
    }

    public function grade(Request $request, QuizAttempt $attempt, GradeAnswerManually $action): JsonResponse
    {
        $attempt->loadMissing('course');
        Gate::authorize('grade-quiz', $attempt->course);

        $validated = $request->validate([
            'grades' => ['required', 'array', 'min:1'],
            'grades.*.question_id' => ['required', 'string', 'exists:questions,uuid'],
            'grades.*.points' => ['required', 'numeric', 'min:0'],
            'grades.*.feedback' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $ids = Question::whereIn('uuid', array_column($validated['grades'], 'question_id'))
            ->pluck('id', 'uuid');

        $grades = array_map(fn (array $g) => [
            'question_id' => (int) ($ids[$g['question_id']] ?? 0),
            'points' => (float) $g['points'],
            'feedback' => $g['feedback'] ?? null,
        ], $validated['grades']);

        $graded = $action->handle($attempt, $grades, $request->user());

        return ApiResponse::ok(AttemptResource::make($graded->load('quiz'))->resolve($request));
    }
}
