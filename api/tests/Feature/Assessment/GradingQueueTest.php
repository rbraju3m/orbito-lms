<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Models\QuizAttempt;
use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(function (): void {
    $this->scenario = quizScenario(['passing_score_percent' => 50]);
    $this->quiz = $this->scenario['quiz'];
    $this->owner = $this->scenario['instructor'];

    $this->essay = Question::factory()->longAnswer()->create(['owner_id' => $this->owner->id]);
    $this->choice = Question::factory()->singleChoice()->create(['owner_id' => $this->owner->id]);
    attachQuestions($this->quiz, [$this->choice, $this->essay]);

    $started = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated();

    $this->attemptId = $started->json('data.attempt.id');

    // Answer the auto-graded one correctly and write an essay.
    $this->actingAs($this->scenario['student'])
        ->patchJson("/api/v1/learn/quiz-attempts/{$this->attemptId}/answers", [
            'question_id' => $this->choice->uuid,
            'answer' => ['option_id' => $this->choice->options->firstWhere('is_correct', true)->id],
        ])->assertOk();

    $this->actingAs($this->scenario['student'])
        ->patchJson("/api/v1/learn/quiz-attempts/{$this->attemptId}/answers", [
            'question_id' => $this->essay->uuid,
            'answer' => ['text' => 'Metre in Bengali verse is counted by syllable.'],
        ])->assertOk();

    $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/quiz-attempts/{$this->attemptId}/submit")->assertOk();
});

it('lists attempts awaiting review', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson("/api/v1/studio/courses/{$this->scenario['course']->uuid}/grading")
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.status'))->toBe('awaiting_review')
        ->and($response->json('data.0.learner.name'))->toBe($this->scenario['student']->name);
});

it('shows the grader the correct answers', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson("/api/v1/studio/grading/{$this->attemptId}")
        ->assertOk();

    // The grader may see them; the learner mid-attempt may not.
    expect(json_encode($response->json('data.review')))->toContain('correct_answer');
});

it('finalises the attempt once the essay is scored', function (): void {
    $response = $this->actingAs($this->owner)->postJson("/api/v1/studio/grading/{$this->attemptId}", [
        'grades' => [
            ['question_id' => $this->essay->uuid, 'points' => 4, 'feedback' => 'Good, but expand on metre.'],
        ],
    ])->assertOk();

    expect($response->json('data.status'))->toBe('graded')
        // 1 of 1 on the choice + 4 of 5 on the essay = 5 of 6.
        ->and((float) $response->json('data.earned_points'))->toBe(5.0)
        ->and($response->json('data.passed'))->toBeTrue();
});

/* A typo must not award more than the question is worth. */
it('clamps a score to the points available', function (): void {
    $this->actingAs($this->owner)->postJson("/api/v1/studio/grading/{$this->attemptId}", [
        'grades' => [['question_id' => $this->essay->uuid, 'points' => 9999]],
    ])->assertOk();

    expect((float) QuizAttempt::first()->earned_points)->toBe(6.0);
});

it('records who graded it and their feedback', function (): void {
    $this->actingAs($this->owner)->postJson("/api/v1/studio/grading/{$this->attemptId}", [
        'grades' => [['question_id' => $this->essay->uuid, 'points' => 3, 'feedback' => 'See me.']],
    ])->assertOk();

    $answer = QuizAttempt::first()->answers->firstWhere('question_id', $this->essay->id);

    expect($answer->graded_by)->toBe($this->owner->id)
        ->and($answer->feedback)->toBe('See me.')
        ->and($answer->graded_at)->not->toBeNull();
});

it('shows the learner their feedback once it is graded', function (): void {
    $this->actingAs($this->owner)->postJson("/api/v1/studio/grading/{$this->attemptId}", [
        'grades' => [['question_id' => $this->essay->uuid, 'points' => 3, 'feedback' => 'Expand on metre.']],
    ])->assertOk();

    $response = $this->actingAs($this->scenario['student'])
        ->getJson("/api/v1/learn/quiz-attempts/{$this->attemptId}/result")
        ->assertOk();

    expect(json_encode($response->json('data.review')))->toContain('Expand on metre.');
});

/* A TA can grade without being able to author — the role's whole purpose. */
it('lets a course-scoped teaching assistant grade', function (): void {
    $ta = User::factory()->withRole(RoleKey::Student)->create();
    $ta->assignRole(RoleKey::TeachingAssistant, $this->scenario['course']);

    $this->actingAs($ta->fresh())->postJson("/api/v1/studio/grading/{$this->attemptId}", [
        'grades' => [['question_id' => $this->essay->uuid, 'points' => 5]],
    ])->assertOk();

    // ...but not to author the quiz.
    $this->actingAs($ta->fresh())
        ->getJson("/api/v1/studio/items/{$this->scenario['item']->uuid}/quiz")
        ->assertStatus(403);
});

it('denies grading to a teaching assistant on another course', function (): void {
    $ta = User::factory()->withRole(RoleKey::Student)->create();
    $other = Course::factory()->published()->create();
    $ta->assignRole(RoleKey::TeachingAssistant, $other);

    $this->actingAs($ta->fresh())->postJson("/api/v1/studio/grading/{$this->attemptId}", [
        'grades' => [['question_id' => $this->essay->uuid, 'points' => 5]],
    ])->assertStatus(403);
});

it('denies the grading queue to a learner', function (): void {
    expect($this->actingAs($this->scenario['student'])
        ->getJson("/api/v1/studio/courses/{$this->scenario['course']->uuid}/grading")
        ->assertStatus(403))->toBeApiError('forbidden');
});

describe('results visibility', function (): void {
    it('reveals the correct answers after submission by default', function (): void {
        $response = $this->actingAs($this->scenario['student'])
            ->getJson("/api/v1/learn/quiz-attempts/{$this->attemptId}/result")
            ->assertOk();

        expect($response->json('data.attempt.quiz.show_correct_answers'))->toBeTrue();
    });

    it('never reveals them when the quiz says never', function (): void {
        $this->quiz->update(['show_correct_answers_after' => 'never']);

        $response = $this->actingAs($this->scenario['student'])
            ->getJson("/api/v1/learn/quiz-attempts/{$this->attemptId}/result")
            ->assertOk();

        expect($response->json('data.attempt.quiz.show_correct_answers'))->toBeFalse()
            ->and(json_encode($response->json('data.review')))->not->toContain('correct_answer');
    });

    it('withholds them from a failing learner when the quiz says on pass', function (): void {
        $this->quiz->update(['show_correct_answers_after' => 'pass', 'passing_score_percent' => 100]);
        $this->actingAs($this->owner)->postJson("/api/v1/studio/grading/{$this->attemptId}", [
            'grades' => [['question_id' => $this->essay->uuid, 'points' => 0]],
        ])->assertOk();

        $response = $this->actingAs($this->scenario['student'])
            ->getJson("/api/v1/learn/quiz-attempts/{$this->attemptId}/result")
            ->assertOk();

        expect($response->json('data.attempt.passed'))->toBeFalse()
            ->and(json_encode($response->json('data.review')))->not->toContain('correct_answer');
    });

    it('refuses a result while the attempt is still open', function (): void {
        $second = quizScenario();
        attachQuestions($second['quiz'], [Question::factory()->singleChoice()->create()]);

        $attemptId = $this->actingAs($second['student'])
            ->postJson("/api/v1/learn/items/{$second['item']->uuid}/quiz/attempts")
            ->assertCreated()->json('data.attempt.id');

        $this->actingAs($second['student'])
            ->getJson("/api/v1/learn/quiz-attempts/{$attemptId}/result")
            ->assertNotFound();
    });
});
