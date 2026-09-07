<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Question;

/*
 * A quiz item is completed by submitting an attempt. The "mark complete"
 * endpoint must not be a way past it — that is the frontend declaring a
 * completion status, which is exactly what is never trusted.
 */

beforeEach(function (): void {
    $this->scenario = quizScenario(['passing_score_percent' => 50]);

    attachQuestions($this->scenario['quiz'], [
        Question::factory()->singleChoice()->create(['owner_id' => $this->scenario['instructor']->id]),
    ]);
});

it('refuses to mark a quiz item complete by hand', function (): void {
    expect($this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/complete")
        ->assertStatus(409))->toBeApiError('progress_rejected');

    $this->assertDatabaseMissing('item_progress', [
        'course_item_id' => $this->scenario['item']->id,
        'status' => 'completed',
    ]);
});

it('refuses to un-complete a quiz item by hand', function (): void {
    $this->actingAs($this->scenario['student'])
        ->deleteJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/complete")
        ->assertStatus(409);
});

it('completes the quiz item when the attempt is submitted', function (): void {
    $started = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated();

    $attemptId = $started->json('data.attempt.id');

    $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")
        ->assertOk();

    $this->assertDatabaseHas('item_progress', [
        'course_item_id' => $this->scenario['item']->id,
        'status' => 'completed',
    ]);
});

it('describes the quiz in the player without giving away its questions', function (): void {
    $body = $this->actingAs($this->scenario['student'])
        ->getJson("/api/v1/learn/items/{$this->scenario['item']->uuid}")
        ->assertOk()
        ->json('data');

    expect($body['content'])->toHaveKeys([
        'instructions', 'question_count', 'time_limit_seconds',
        'attempts_allowed', 'passing_score_percent',
    ])
        ->and($body['content']['question_count'])->toBe(1)
        ->and($body['content'])->not->toHaveKey('questions');
});

it('tells the player that a quiz cannot be self-marked', function (): void {
    $curriculum = $this->actingAs($this->scenario['student'])
        ->getJson("/api/v1/learn/courses/{$this->scenario['course']->uuid}")
        ->assertOk()
        ->json('data.curriculum');

    $item = collect($curriculum)->pluck('items')->flatten(1)->firstWhere('type', 'quiz');

    expect($item['is_completable'])->toBeTrue()
        ->and($item['is_self_markable'])->toBeFalse();
});

/*
 * A results screen showing {"option_id": 47} tells the learner nothing. Only
 * the server holds the labels, so it renders the readable form.
 */
it('renders the learner own answer with labels, not option ids', function (): void {
    $started = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated();

    $attemptId = $started->json('data.attempt.id');
    $question = $started->json('data.questions.0');
    $chosen = $question['options'][0];

    $this->actingAs($this->scenario['student'])
        ->patchJson("/api/v1/learn/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $question['id'],
            'answer' => ['option_id' => $chosen['id']],
        ])->assertOk();

    $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")->assertOk();

    $row = $this->actingAs($this->scenario['student'])
        ->getJson("/api/v1/learn/quiz-attempts/{$attemptId}/result")
        ->assertOk()
        ->json('data.review.0');

    expect($row['your_answer_label'])->toBe($chosen['label']);
});

it('reports no answer at all rather than an empty label', function (): void {
    $attemptId = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated()->json('data.attempt.id');

    $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")->assertOk();

    $row = $this->actingAs($this->scenario['student'])
        ->getJson("/api/v1/learn/quiz-attempts/{$attemptId}/result")
        ->assertOk()
        ->json('data.review.0');

    expect($row['your_answer_label'])->toBeNull()
        ->and((float) $row['points_earned'])->toBe(0.0);
});

/*
 * A blank essay has nothing for a person to read. Queueing it would leave the
 * learner's whole result "pending" until an instructor clicked through empty
 * answers, which is worse than scoring the blank zero.
 */
it('grades an attempt with an unanswered essay rather than queueing it', function (): void {
    $scenario = quizScenario(['passing_score_percent' => 50]);
    attachQuestions($scenario['quiz'], [
        Question::factory()->singleChoice()->create(['owner_id' => $scenario['instructor']->id]),
        Question::factory()->longAnswer()->create(['owner_id' => $scenario['instructor']->id]),
    ]);

    $attemptId = $this->actingAs($scenario['student'])
        ->postJson("/api/v1/learn/items/{$scenario['item']->uuid}/quiz/attempts")
        ->assertCreated()->json('data.attempt.id');

    $graded = $this->actingAs($scenario['student'])
        ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")
        ->assertOk()->json('data');

    expect($graded['status'])->toBe('graded')
        ->and((float) $graded['percent'])->toBe(0.0);

    $this->actingAs($scenario['instructor'])
        ->getJson("/api/v1/studio/courses/{$scenario['course']->uuid}/grading")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('still queues an essay the learner actually wrote', function (): void {
    $scenario = quizScenario();
    $essay = Question::factory()->longAnswer()->create(['owner_id' => $scenario['instructor']->id]);
    attachQuestions($scenario['quiz'], [$essay]);

    $attemptId = $this->actingAs($scenario['student'])
        ->postJson("/api/v1/learn/items/{$scenario['item']->uuid}/quiz/attempts")
        ->assertCreated()->json('data.attempt.id');

    $this->actingAs($scenario['student'])
        ->patchJson("/api/v1/learn/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $essay->uuid,
            'answer' => ['text' => 'Tagore bends payar into something conversational.'],
        ])->assertOk();

    expect($this->actingAs($scenario['student'])
        ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")
        ->assertOk()->json('data.status'))->toBe('awaiting_review');
});
