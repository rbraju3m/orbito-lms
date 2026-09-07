<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Assessment;

use App\Domain\Assessment\Actions\ManageQuizQuestions;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Curriculum\Models\CourseItem;
use App\Http\Requests\Assessment\StoreQuestionRequest;
use App\Http\Requests\Assessment\UpdateQuizRequest;
use App\Http\Resources\Assessment\QuestionResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Quiz authoring. Every route here returns the AUTHORING question shape, which
 * includes correct answers — hence the course-scoped authorization on each one.
 */
final class QuizBuilderController
{
    public function __construct(private readonly ManageQuizQuestions $questions) {}

    public function show(CourseItem $item): JsonResponse
    {
        $quiz = $this->quizFor($item);
        Gate::authorize('manage-quiz', $item->course);

        return ApiResponse::ok([
            'settings' => $this->settings($quiz),
            'questions' => QuestionResource::collection(
                $quiz->questions()->with('options')->get()
            )->resolve(request()),
        ]);
    }

    public function update(UpdateQuizRequest $request, CourseItem $item): JsonResponse
    {
        $quiz = $this->quizFor($item);
        Gate::authorize('manage-quiz', $item->course);

        $quiz->fill($request->validated())->save();

        return ApiResponse::ok($this->settings($quiz->refresh()));
    }

    public function storeQuestion(StoreQuestionRequest $request, CourseItem $item): JsonResponse
    {
        $quiz = $this->quizFor($item);
        Gate::authorize('manage-quiz', $item->course);

        $question = $this->questions->create(
            $quiz,
            $request->user(),
            $request->safe()->except('options'),
            $request->options(),
        );

        return ApiResponse::created(QuestionResource::make($question->load('options')));
    }

    public function updateQuestion(
        StoreQuestionRequest $request,
        CourseItem $item,
        Question $question,
    ): JsonResponse {
        $quiz = $this->quizFor($item);
        Gate::authorize('manage-quiz', $item->course);
        $this->assertBelongs($quiz, $question);

        $updated = $this->questions->update(
            $question,
            $request->safe()->except('options'),
            $request->has('options') ? $request->options() : null,
        );

        return ApiResponse::ok(QuestionResource::make($updated));
    }

    public function destroyQuestion(CourseItem $item, Question $question): JsonResponse
    {
        $quiz = $this->quizFor($item);
        Gate::authorize('manage-quiz', $item->course);
        $this->assertBelongs($quiz, $question);

        $this->questions->detach($quiz, $question);
        $question->delete();

        return ApiResponse::noContent();
    }

    public function reorderQuestions(CourseItem $item): JsonResponse
    {
        $quiz = $this->quizFor($item);
        Gate::authorize('manage-quiz', $item->course);

        $uuids = request()->validate([
            'question_ids' => ['required', 'array'],
            'question_ids.*' => ['string', 'exists:questions,uuid'],
        ])['question_ids'];

        $ids = Question::whereIn('uuid', $uuids)->pluck('id', 'uuid');

        foreach (array_values($uuids) as $position => $uuid) {
            DB::table('quiz_questions')
                ->where('quiz_id', $quiz->id)
                ->where('question_id', $ids[$uuid] ?? 0)
                ->update(['position' => $position, 'updated_at' => now()]);
        }

        return ApiResponse::ok(
            QuestionResource::collection($quiz->questions()->with('options')->get())
        );
    }

    /**
     * The {question} binding is deliberately unscoped (a question can be
     * shared with a bank), so membership of THIS quiz is checked explicitly.
     * Without it, authoring one's own quiz would grant edit and delete on any
     * question id in the system.
     */
    private function assertBelongs(Quiz $quiz, Question $question): void
    {
        if (! $quiz->questions()->whereKey($question->getKey())->exists()) {
            throw new NotFoundHttpException;
        }
    }

    /** @return array<string, mixed> */
    private function settings(Quiz $quiz): array
    {
        return $quiz->only([
            'description', 'instructions', 'time_limit_seconds', 'time_expiry_policy',
            'attempts_allowed', 'passing_score_percent', 'grading_policy', 'question_order',
            'shuffle_answers', 'questions_per_attempt', 'questions_per_page',
            'hide_question_numbers', 'feedback_mode', 'show_correct_answers_after',
            'negative_marking', 'allow_previous_button',
        ]);
    }

    private function quizFor(CourseItem $item): Quiz
    {
        $item->loadMissing(['course', 'itemable']);

        if (! $item->itemable instanceof Quiz) {
            throw new NotFoundHttpException;
        }

        return $item->itemable;
    }
}
