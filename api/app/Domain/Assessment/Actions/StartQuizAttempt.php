<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Enums\AttemptStatus;
use App\Domain\Assessment\Exceptions\AttemptRejected;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\QuizAttempt;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StartQuizAttempt
{
    public function handle(
        Enrollment $enrollment,
        CourseItem $item,
        Quiz $quiz,
        Request $request,
    ): QuizAttempt {
        $quiz->loadMissing('questions');

        if ($quiz->questions->isEmpty()) {
            throw AttemptRejected::noQuestions();
        }

        $existing = QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $enrollment->user_id)
            ->orderByDesc('attempt_number')
            ->get();

        // Resume rather than start again: a dropped connection must not burn
        // an attempt.
        $open = $existing->firstWhere(fn (QuizAttempt $a) => $a->status->isOpen());

        if ($open !== null) {
            return $open;
        }

        $used = $existing->filter(fn (QuizAttempt $a) => $a->status->countsAsCompleted())->count();

        if ($quiz->attempts_allowed !== null && $used >= $quiz->attempts_allowed) {
            throw AttemptRejected::noAttemptsLeft($quiz->attempts_allowed);
        }

        return DB::transaction(function () use ($enrollment, $item, $quiz, $request, $existing): QuizAttempt {
            $order = $this->questionOrder($quiz);

            return QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'course_item_id' => $item->id,
                'course_id' => $enrollment->course_id,
                'user_id' => $enrollment->user_id,
                'enrollment_id' => $enrollment->id,
                'attempt_number' => (int) ($existing->max('attempt_number') ?? 0) + 1,
                'status' => AttemptStatus::InProgress,
                'started_at' => now(),
                // The deadline is decided HERE, by the server, and every
                // submission is judged against it (ADR-06).
                'expires_at' => $quiz->hasTimeLimit()
                    ? now()->addSeconds($quiz->time_limit_seconds)
                    : null,
                'question_order' => $order,
                'total_points' => $this->totalPoints($quiz, $order),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
        });
    }

    /**
     * The exact questions this attempt will be asked, in the order they will be
     * served. Frozen at start so a random subset stays stable across paging,
     * resume and grading.
     *
     * @return list<int>
     */
    private function questionOrder(Quiz $quiz): array
    {
        $ids = $quiz->questions->pluck('id')->map(fn ($id) => (int) $id);

        if ($quiz->question_order === 'random') {
            $ids = $ids->shuffle();
        }

        if ($quiz->questions_per_attempt !== null) {
            $ids = $ids->take($quiz->questions_per_attempt);
        }

        return $ids->values()->all();
    }

    /** @param  list<int>  $order */
    private function totalPoints(Quiz $quiz, array $order): float
    {
        return (float) $quiz->questions
            ->whereIn('id', $order)
            ->sum(fn ($question) => $quiz->pointsFor($question));
    }
}
