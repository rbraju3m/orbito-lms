<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Question;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

/*
 * ADR-06: the correct answers must never reach an in-progress attempt, and the
 * deadline is the server's alone.
 *
 * These are the tests that make the guarantee real. If one of them fails, a
 * learner can see the answers or extend their own time.
 */

beforeEach(function (): void {
    $this->scenario = quizScenario(['time_limit_seconds' => 600]);
    $this->quiz = $this->scenario['quiz'];

    attachQuestions($this->quiz, [
        Question::factory()->singleChoice()->create(['owner_id' => $this->scenario['instructor']->id]),
        Question::factory()->multipleChoice()->create(['owner_id' => $this->scenario['instructor']->id]),
        Question::factory()->matching()->create(['owner_id' => $this->scenario['instructor']->id]),
        Question::factory()->shortAnswer()->create(['owner_id' => $this->scenario['instructor']->id]),
        Question::factory()->fillBlank()->create(['owner_id' => $this->scenario['instructor']->id]),
        Question::factory()->ordering()->create(['owner_id' => $this->scenario['instructor']->id]),
    ]);

    $this->start = fn () => $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts");
});

it('never sends is_correct to an in-progress attempt', function (): void {
    $body = ($this->start)()->assertCreated()->json();

    expect(json_encode($body))->not->toContain('is_correct');
});

it('never sends an explanation to an in-progress attempt', function (): void {
    $body = ($this->start)()->assertCreated()->json();

    expect(json_encode($body))->not->toContain('Because it does');
});

it('never sends match keys as a lookup an attempt could reverse', function (): void {
    $questions = ($this->start)()->assertCreated()->json('data.questions');

    $matching = collect($questions)->firstWhere('type', 'matching');

    // Targets are served shuffled and detached from the option they belong to,
    // so knowing the list does not reveal the pairing.
    expect($matching['match_targets'])->toHaveCount(3);

    foreach ($matching['options'] as $option) {
        expect($option)->not->toHaveKey('match_key')
            ->and($option)->not->toHaveKey('is_correct');
    }
});

it('never sends accepted answers for a short answer question', function (): void {
    $body = json_encode(($this->start)()->assertCreated()->json());

    expect($body)->not->toContain('Rabindranath Tagore')
        ->and($body)->not->toContain('accepted');
});

it('sends only the blank count for a fill-in-the-blank question', function (): void {
    $questions = ($this->start)()->assertCreated()->json('data.questions');

    $fill = collect($questions)->firstWhere('type', 'fill_blank');

    expect($fill['blank_count'])->toBe(2)
        ->and(json_encode($fill))->not->toContain('Gitanjali');
});

it('never sends the correct sequence for an ordering question', function (): void {
    $questions = ($this->start)()->assertCreated()->json('data.questions');

    $ordering = collect($questions)->firstWhere('type', 'ordering');

    foreach ($ordering['options'] as $option) {
        expect($option)->not->toHaveKey('position');
    }
});

it('does not report a score while the attempt is open', function (): void {
    $attempt = ($this->start)()->assertCreated()->json('data.attempt');

    expect($attempt)->not->toHaveKey('earned_points')
        ->and($attempt)->not->toHaveKey('percent')
        ->and($attempt)->not->toHaveKey('result');
});

it('does not tell the learner whether a saved answer was right', function (): void {
    $started = ($this->start)()->assertCreated();
    $attemptId = $started->json('data.attempt.id');
    $question = $started->json('data.questions.0');

    $response = $this->actingAs($this->scenario['student'])
        ->patchJson("/api/v1/learn/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $question['id'],
            'answer' => ['option_id' => $question['options'][0]['id']],
        ])->assertOk();

    expect($response->json('data'))->toHaveKeys(['saved', 'seconds_remaining'])
        ->and($response->json('data'))->not->toHaveKey('is_correct')
        ->and($response->json('data'))->not->toHaveKey('points_earned');
});

/* The countdown is a display of the server's deadline, not a source of truth. */
it('derives the countdown from the deadline the server recorded', function (): void {
    $attempt = ($this->start)()->assertCreated()->json('data.attempt');

    expect($attempt['seconds_remaining'])->toBeLessThanOrEqual(600)
        ->and($attempt['seconds_remaining'])->toBeGreaterThan(590)
        ->and($attempt['expires_at'])->not->toBeNull();
});

it('refuses a saved answer after the deadline, whatever the client believes', function (): void {
    $started = ($this->start)()->assertCreated();
    $attemptId = $started->json('data.attempt.id');
    $question = $started->json('data.questions.0');

    $this->travel(11)->minutes();

    expect($this->actingAs($this->scenario['student'])
        ->patchJson("/api/v1/learn/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $question['id'],
            'answer' => ['option_id' => $question['options'][0]['id']],
        ])->assertStatus(409))->toBeApiError('attempt_rejected');
});

it('refuses to answer a question that is not part of this attempt', function (): void {
    $attemptId = ($this->start)()->assertCreated()->json('data.attempt.id');

    $foreign = Question::factory()->singleChoice()->create();

    // Otherwise a random-subset quiz could be answered in full.
    $this->actingAs($this->scenario['student'])
        ->patchJson("/api/v1/learn/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $foreign->uuid,
            'answer' => ['option_id' => $foreign->options->first()->id],
        ])->assertNotFound();
});

it('hides another learner attempt behind a 404', function (): void {
    $attemptId = ($this->start)()->assertCreated()->json('data.attempt.id');

    $other = User::factory()->withRole(RoleKey::Student)->create();

    // 404 rather than 403: the endpoint must not confirm the attempt exists.
    $this->actingAs($other)->getJson("/api/v1/learn/quiz-attempts/{$attemptId}")->assertNotFound();
});

it('does not let a learner reach the authoring view of a quiz', function (): void {
    expect($this->actingAs($this->scenario['student'])
        ->getJson("/api/v1/studio/items/{$this->scenario['item']->uuid}/quiz")
        ->assertStatus(403))->toBeApiError('forbidden');
});

it('does not let an unrelated instructor read another course quiz', function (): void {
    $stranger = User::factory()->instructor()->create();

    $this->actingAs($stranger)
        ->getJson("/api/v1/studio/items/{$this->scenario['item']->uuid}/quiz")
        ->assertStatus(403);
});

/*
 * Collection::shuffle() is random on every call, so shuffled options would jump
 * around between page loads of the same attempt. The order is derived from a
 * hash of (attempt, option) instead: stable per attempt, different per learner.
 */
it('shuffles answers stably within one attempt', function (): void {
    $this->quiz->update(['shuffle_answers' => true]);

    $started = ($this->start)()->assertCreated();
    $attemptId = $started->json('data.attempt.id');
    $first = collect($started->json('data.questions.0.options'))->pluck('id')->all();

    foreach (range(1, 3) as $_) {
        $reloaded = $this->actingAs($this->scenario['student'])
            ->getJson("/api/v1/learn/quiz-attempts/{$attemptId}")->assertOk();

        expect(collect($reloaded->json('data.questions.0.options'))->pluck('id')->all())->toBe($first);
    }
});

/*
 * The {question} route binding is unscoped, so authoring one's own quiz must
 * not become a licence to edit or delete any question id in the system.
 */
it('refuses to edit a question that belongs to another quiz', function (): void {
    $foreign = Question::factory()->singleChoice()->create();

    $this->actingAs($this->scenario['instructor'])
        ->patchJson(
            "/api/v1/studio/items/{$this->scenario['item']->uuid}/quiz/questions/{$foreign->uuid}",
            ['type' => 'single_choice', 'title' => 'Rewritten', 'options' => [
                ['label' => 'a', 'is_correct' => true],
                ['label' => 'b'],
            ]],
        )->assertNotFound();

    expect($foreign->refresh()->title)->not->toBe('Rewritten');
});

it('refuses to delete a question that belongs to another quiz', function (): void {
    $foreign = Question::factory()->singleChoice()->create();

    $this->actingAs($this->scenario['instructor'])
        ->deleteJson(
            "/api/v1/studio/items/{$this->scenario['item']->uuid}/quiz/questions/{$foreign->uuid}"
        )->assertNotFound();

    $this->assertDatabaseHas('questions', ['id' => $foreign->id]);
});
