<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Assessment;

use App\Domain\Assessment\Actions\SaveAttemptAnswer;
use App\Domain\Assessment\Actions\StartQuizAttempt;
use App\Domain\Assessment\Actions\SubmitQuizAttempt;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\QuizAttempt;
use App\Domain\Assessment\Queries\AttemptQuery;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Exceptions\ContentLocked;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Queries\AccessDecision;
use App\Domain\Enrollment\Queries\CourseAccess;
use App\Http\Requests\Assessment\SaveAnswerRequest;
use App\Http\Resources\Assessment\AttemptQuestionResource;
use App\Http\Resources\Assessment\AttemptResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The learner-facing quiz runner.
 *
 * ADR-06 holds across every route here: questions are served through
 * AttemptQuestionResource (no correct answers), the deadline is the server's,
 * and grading happens only in SubmitQuizAttempt.
 */
final class AttemptController
{
    public function __construct(private readonly CourseAccess $access) {}

    /** Starts a new attempt, or resumes one already open. */
    public function store(Request $request, CourseItem $item, StartQuizAttempt $action): JsonResponse
    {
        $quiz = $this->quizFor($item);
        $enrollment = $this->enrollmentFor($request, $item);

        $attempt = $action->handle($enrollment, $item, $quiz, $request);

        return ApiResponse::created($this->runnerPayload($attempt, app(AttemptQuery::class), $request));
    }

    /** The runner state: questions, saved answers and the countdown. */
    public function show(Request $request, QuizAttempt $attempt, AttemptQuery $query): JsonResponse
    {
        $this->assertOwner($request, $attempt);

        return ApiResponse::ok($this->runnerPayload($attempt, $query, $request));
    }

    public function saveAnswer(
        SaveAnswerRequest $request,
        QuizAttempt $attempt,
        SaveAttemptAnswer $action,
    ): JsonResponse {
        $this->assertOwner($request, $attempt);

        $question = Question::where('uuid', $request->string('question_id')->value())->firstOrFail();

        // A question that is not part of THIS attempt cannot be answered —
        // otherwise a random-subset quiz could be answered in full.
        if (! in_array($question->id, $attempt->question_order ?? [], true)) {
            throw new NotFoundHttpException;
        }

        $action->handle($attempt, $question, (array) $request->input('answer', []));

        // Deliberately returns no score: the attempt is still open.
        return ApiResponse::ok([
            'saved' => true,
            'seconds_remaining' => $attempt->secondsRemaining(),
        ]);
    }

    public function submit(Request $request, QuizAttempt $attempt, SubmitQuizAttempt $action): JsonResponse
    {
        $this->assertOwner($request, $attempt);

        $graded = $action->handle($attempt);

        return ApiResponse::ok(
            AttemptResource::make($graded->load('quiz'))->resolve($request)
        );
    }

    /** The results screen. */
    public function result(Request $request, QuizAttempt $attempt, AttemptQuery $query): JsonResponse
    {
        $this->assertOwner($request, $attempt);
        $attempt->loadMissing('quiz');

        if ($attempt->status->isOpen()) {
            throw new NotFoundHttpException;
        }

        $resource = AttemptResource::make($attempt);

        return ApiResponse::ok([
            'attempt' => $resource->resolve($request),
            'review' => $query->review($attempt, $resource->mayRevealAnswers()),
        ]);
    }

    /** Every attempt this learner has made at this quiz. */
    public function index(Request $request, CourseItem $item): JsonResponse
    {
        $quiz = $this->quizFor($item);
        $this->enrollmentFor($request, $item);

        $attempts = QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $request->user()->id)
            ->with('quiz')
            ->orderByDesc('attempt_number')
            ->get();

        return ApiResponse::ok([
            'attempts' => AttemptResource::collection($attempts)->resolve($request),
            'attempts_allowed' => $quiz->attempts_allowed,
            'attempts_used' => $attempts
                ->filter(fn (QuizAttempt $a) => $a->status->countsAsCompleted())
                ->count(),
        ]);
    }

    /** @return array<string, mixed> */
    private function runnerPayload(QuizAttempt $attempt, AttemptQuery $query, Request $request): array
    {
        $attempt->loadMissing('quiz');

        return [
            'attempt' => AttemptResource::make($attempt)->resolve($request),
            'questions' => $attempt->status->isOpen()
                ? AttemptQuestionResource::collection($query->questionsFor($attempt))->resolve($request)
                : [],
            'answers' => $attempt->status->isOpen() ? (object) $query->savedAnswers($attempt) : (object) [],
        ];
    }

    private function assertOwner(Request $request, QuizAttempt $attempt): void
    {
        // An attempt belongs to exactly one learner. 404 rather than 403 so the
        // endpoint does not confirm someone else's attempt exists.
        if ($attempt->user_id !== $request->user()?->id) {
            throw new NotFoundHttpException;
        }
    }

    private function quizFor(CourseItem $item): Quiz
    {
        $item->loadMissing(['course', 'itemable']);

        if (! $item->itemable instanceof Quiz || ! $item->is_published) {
            throw new NotFoundHttpException;
        }

        return $item->itemable;
    }

    private function enrollmentFor(Request $request, CourseItem $item): Enrollment
    {
        $decision = $this->access->forItem($request->user(), $item);

        if (! $decision->granted) {
            throw ContentLocked::from($decision);
        }

        $enrollment = $decision->enrollment
            ?? ($request->user() !== null
                ? $this->access->enrollmentFor($request->user(), $item->course)
                : null);

        // Course staff can preview a quiz but have no enrollment to attempt
        // against, and inventing one would corrupt their students' statistics.
        if ($enrollment === null || ! $enrollment->isActive()) {
            throw ContentLocked::from(AccessDecision::deny('not_enrolled'));
        }

        return $enrollment;
    }
}
