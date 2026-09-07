<?php

declare(strict_types=1);

use App\Domain\Assessment\Enums\AttemptStatus;
use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuizAttempt;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Enums\ItemProgressStatus;
use App\Domain\Progress\Models\ItemProgress;

/** Answers every question correctly, then submits. */
function answerAllCorrectly(object $test, string $attemptId, array $questions, array $models): void
{
    foreach ($questions as $served) {
        $question = collect($models)->first(fn (Question $q) => $q->uuid === $served['id']);

        $answer = match ($served['type']) {
            'single_choice', 'true_false', 'image_choice' => [
                'option_id' => $question->options->firstWhere('is_correct', true)->id,
            ],
            'multiple_choice' => [
                'option_ids' => $question->options->where('is_correct', true)->pluck('id')->all(),
            ],
            'ordering' => [
                'option_ids' => $question->options->sortBy('position')->pluck('id')->values()->all(),
            ],
            'matching', 'image_matching' => [
                'pairs' => $question->options
                    ->mapWithKeys(fn ($o) => [(string) $o->id => $o->match_key])->all(),
            ],
            'short_answer' => ['text' => 'Tagore'],
            'fill_blank' => ['blanks' => ['Tagore', 'Gitanjali']],
            'long_answer' => ['text' => 'A considered essay about metre.'],
            default => [],
        };

        $test->actingAs($test->scenario['student'])
            ->patchJson("/api/v1/learn/quiz-attempts/{$attemptId}/answers", [
                'question_id' => $served['id'],
                'answer' => $answer,
            ])->assertOk();
    }
}

beforeEach(function (): void {
    $this->scenario = quizScenario(['passing_score_percent' => 60]);
    $this->quiz = $this->scenario['quiz'];
    $this->owner = $this->scenario['instructor'];
});

it('starts an attempt and serves the questions', function (): void {
    attachQuestions($this->quiz, [Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id])]);

    $response = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated();

    expect($response->json('data.attempt.attempt_number'))->toBe(1)
        ->and($response->json('data.attempt.status'))->toBe('in_progress')
        ->and($response->json('data.questions'))->toHaveCount(1);
});

/* A dropped connection must not burn an attempt. */
it('resumes an open attempt instead of starting another', function (): void {
    attachQuestions($this->quiz, [Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id])]);

    $first = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated()->json('data.attempt.id');

    $second = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated()->json('data.attempt.id');

    expect($second)->toBe($first)->and(QuizAttempt::count())->toBe(1);
});

it('refuses to start a quiz with no questions', function (): void {
    expect($this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertStatus(409))->toBeApiError('attempt_rejected');
});

it('refuses an attempt from someone not enrolled', function (): void {
    attachQuestions($this->quiz, [Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id])]);
    $stranger = User::factory()
        ->withRole(RoleKey::Student)->create();

    expect($this->actingAs($stranger)
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertStatus(423))->toBeApiError('content_locked');
});

/* Course staff have no enrollment to attempt against. */
it('refuses an attempt from course staff', function (): void {
    attachQuestions($this->quiz, [Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id])]);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertStatus(423);
});

it('grades every question type in one attempt', function (): void {
    $models = [
        Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id]),
        Question::factory()->trueFalse()->create(['owner_id' => $this->owner->id]),
        Question::factory()->multipleChoice()->create(['owner_id' => $this->owner->id]),
        Question::factory()->ordering()->create(['owner_id' => $this->owner->id]),
        Question::factory()->matching()->create(['owner_id' => $this->owner->id]),
        Question::factory()->shortAnswer()->create(['owner_id' => $this->owner->id]),
        Question::factory()->fillBlank()->create(['owner_id' => $this->owner->id]),
        Question::factory()->imageChoice()->create(['owner_id' => $this->owner->id]),
        Question::factory()->imageMatching()->create(['owner_id' => $this->owner->id]),
    ];
    attachQuestions($this->quiz, $models);

    $started = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated();

    $attemptId = $started->json('data.attempt.id');
    answerAllCorrectly($this, $attemptId, $started->json('data.questions'), $models);

    $result = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")
        ->assertOk();

    expect($result->json('data.status'))->toBe('graded')
        ->and((float) $result->json('data.percent'))->toBe(100.0)
        ->and($result->json('data.passed'))->toBeTrue();
});

it('routes a long answer to the review queue', function (): void {
    $models = [
        Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id]),
        Question::factory()->longAnswer()->create(['owner_id' => $this->owner->id]),
    ];
    attachQuestions($this->quiz, $models);

    $started = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated();
    $attemptId = $started->json('data.attempt.id');

    answerAllCorrectly($this, $attemptId, $started->json('data.questions'), $models);

    $result = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")
        ->assertOk();

    // Pending, not failed: nobody has looked at the essay yet.
    expect($result->json('data.status'))->toBe('awaiting_review')
        ->and($result->json('data.result'))->toBe('pending');
});

it('enforces the attempt limit', function (): void {
    $this->quiz->update(['attempts_allowed' => 1]);
    attachQuestions($this->quiz, [Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id])]);

    $attemptId = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated()->json('data.attempt.id');

    $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")->assertOk();

    expect($this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertStatus(409))->toBeApiError('attempt_rejected');
});

it('serves a random subset and freezes it for the attempt', function (): void {
    $this->quiz->update(['question_order' => 'random', 'questions_per_attempt' => 2]);
    attachQuestions($this->quiz, [
        Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id]),
        Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id]),
        Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id]),
        Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id]),
    ]);

    $started = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated();

    $first = collect($started->json('data.questions'))->pluck('id')->all();
    expect($first)->toHaveCount(2);

    // Reloading the runner must serve the same two questions in the same order.
    $reloaded = $this->actingAs($this->scenario['student'])
        ->getJson("/api/v1/learn/quiz-attempts/{$started->json('data.attempt.id')}")
        ->assertOk();

    expect(collect($reloaded->json('data.questions'))->pluck('id')->all())->toBe($first);
});

it('scores only the subset it served', function (): void {
    $this->quiz->update(['questions_per_attempt' => 1]);
    attachQuestions($this->quiz, [
        Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id]),
        Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id]),
    ]);

    $started = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated();

    $result = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/quiz-attempts/{$started->json('data.attempt.id')}/submit")
        ->assertOk();

    expect((float) $result->json('data.total_points'))->toBe(1.0);
});

describe('time limits', function (): void {
    it('auto-submits a late attempt when that is the policy', function (): void {
        $this->quiz->update(['time_limit_seconds' => 60, 'time_expiry_policy' => 'auto_submit']);
        $models = [Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id])];
        attachQuestions($this->quiz, $models);

        $started = $this->actingAs($this->scenario['student'])
            ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
            ->assertCreated();
        $attemptId = $started->json('data.attempt.id');
        answerAllCorrectly($this, $attemptId, $started->json('data.questions'), $models);

        $this->travel(2)->minutes();

        // Whatever was answered still counts.
        $result = $this->actingAs($this->scenario['student'])
            ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")->assertOk();

        expect($result->json('data.status'))->toBe('graded')
            ->and((float) $result->json('data.percent'))->toBe(100.0);
    });

    it('discards a late attempt when the policy says abandon', function (): void {
        $this->quiz->update(['time_limit_seconds' => 60, 'time_expiry_policy' => 'auto_abandon']);
        $models = [Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id])];
        attachQuestions($this->quiz, $models);

        $started = $this->actingAs($this->scenario['student'])
            ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
            ->assertCreated();
        $attemptId = $started->json('data.attempt.id');
        answerAllCorrectly($this, $attemptId, $started->json('data.questions'), $models);

        $this->travel(2)->minutes();

        $result = $this->actingAs($this->scenario['student'])
            ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")->assertOk();

        expect($result->json('data.status'))->toBe('expired')
            ->and((float) $result->json('data.percent'))->toBe(0.0);
    });

    /* Nothing DEPENDS on the sweeper; it just stops attempts sitting open. */
    it('resolves abandoned attempts through the sweeper', function (): void {
        $this->quiz->update(['time_limit_seconds' => 60]);
        attachQuestions($this->quiz, [Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id])]);

        $this->actingAs($this->scenario['student'])
            ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")->assertCreated();

        $this->travel(2)->minutes();

        $this->artisan('quiz:sweep-expired')->expectsOutputToContain('Resolved 1')->assertSuccessful();

        expect(QuizAttempt::first()->status)->toBe(AttemptStatus::Graded);
    });
});

it('marks the curriculum item complete on submission', function (): void {
    attachQuestions($this->quiz, [Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id])]);

    $attemptId = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated()->json('data.attempt.id');

    $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")->assertOk();

    $progress = ItemProgress::where('course_item_id', $this->scenario['item']->id)->first();
    expect($progress->status)->toBe(ItemProgressStatus::Completed);
});

it('refuses to submit twice', function (): void {
    attachQuestions($this->quiz, [Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id])]);

    $attemptId = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated()->json('data.attempt.id');

    $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")->assertOk();

    $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")->assertStatus(409);
});
