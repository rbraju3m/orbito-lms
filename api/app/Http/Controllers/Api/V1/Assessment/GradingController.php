<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Assessment;

use App\Domain\Assessment\Actions\GradeAnswerManually;
use App\Domain\Assessment\Actions\GradeSubmission;
use App\Domain\Assessment\Actions\ReturnSubmission;
use App\Domain\Assessment\Models\AssignmentSubmission;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuizAttempt;
use App\Domain\Assessment\Queries\AttemptQuery;
use App\Domain\Assessment\Queries\GradingQueue;
use App\Domain\Catalog\Models\Course;
use App\Http\Resources\Assessment\AssignmentResource;
use App\Http\Resources\Assessment\AttemptResource;
use App\Http\Resources\Assessment\SubmissionResource;
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
    /**
     * Everything waiting to be marked, quizzes and assignments together.
     *
     * A grader who may only mark one of the two sees only that one: the queue
     * is filtered by what this particular reader is allowed to open, not by a
     * query-string parameter.
     */
    public function queue(Request $request, Course $course, GradingQueue $queue): JsonResponse
    {
        Gate::authorize('view-grading-queue', $course);

        $perPage = min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );

        return ApiResponse::ok($queue->forCourse(
            $course,
            includeQuizzes: Gate::allows('view-quiz-attempts', $course),
            includeAssignments: Gate::allows('view-submissions', $course),
            status: $request->string('status', 'awaiting_review')->value(),
            perPage: $perPage,
        ));
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

    /** One submission, with everything the grader needs in order to mark it. */
    public function showSubmission(Request $request, AssignmentSubmission $submission): JsonResponse
    {
        $submission->loadMissing(['course', 'assignment.attachments', 'user', 'files.media', 'item']);
        Gate::authorize('view-submissions', $submission->course);

        return ApiResponse::ok([
            'submission' => SubmissionResource::make($submission)->resolve($request),
            'assignment' => AssignmentResource::make($submission->assignment)->resolve($request),
            'learner' => ['id' => $submission->user?->uuid, 'name' => $submission->user?->name],
            'item' => ['id' => $submission->item?->uuid, 'title' => $submission->item?->title],
        ]);
    }

    public function gradeSubmission(
        Request $request,
        AssignmentSubmission $submission,
        GradeSubmission $action,
    ): JsonResponse {
        $submission->loadMissing(['course', 'assignment']);
        Gate::authorize('grade-assignment', $submission->course);

        $validated = $request->validate([
            'points' => ['required', 'numeric', 'min:0'],
            'feedback' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        $graded = $action->handle(
            $submission,
            (float) $validated['points'],
            $validated['feedback'] ?? null,
            $request->user(),
        );

        return ApiResponse::ok(
            SubmissionResource::make($graded->load('files.media'))->resolve($request)
        );
    }

    /** Hand it back for another go, without a mark. */
    public function returnSubmission(
        Request $request,
        AssignmentSubmission $submission,
        ReturnSubmission $action,
    ): JsonResponse {
        $submission->loadMissing('course');
        Gate::authorize('grade-assignment', $submission->course);

        $validated = $request->validate([
            'feedback' => ['required', 'string', 'max:20000'],
        ]);

        $returned = $action->handle($submission, $validated['feedback'], $request->user());

        return ApiResponse::ok(
            SubmissionResource::make($returned->load('files.media'))->resolve($request)
        );
    }
}
