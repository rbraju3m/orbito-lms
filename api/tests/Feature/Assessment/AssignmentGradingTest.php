<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\AssignmentSubmission;
use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(function (): void {
    $this->scenario = assignmentScenario(['total_points' => 50, 'passing_points' => 25]);
    $this->owner = $this->scenario['instructor'];

    $this->submissionId = $this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/assignment/submissions", [
            'body' => '<p>My close reading.</p>',
        ])->assertCreated()->json('data.id');

    $this->url = "/api/v1/studio/grading/assignment/{$this->submissionId}";
});

it('shows the grader the work, the brief and who wrote it', function (): void {
    $body = $this->actingAs($this->owner)->getJson($this->url)->assertOk()->json('data');

    expect($body)->toHaveKeys(['submission', 'assignment', 'learner', 'item'])
        ->and($body['submission']['body'])->toContain('close reading')
        ->and($body['learner']['name'])->toBe($this->scenario['student']->name)
        ->and((float) $body['assignment']['total_points'])->toBe(50.0);
});

it('records the mark, the feedback and who gave it', function (): void {
    $body = $this->actingAs($this->owner)->postJson($this->url, [
        'points' => 40,
        'feedback' => '<p>Strong on imagery.</p>',
    ])->assertOk()->json('data');

    expect($body['status'])->toBe('graded')
        ->and((float) $body['points_earned'])->toBe(40.0)
        ->and($body['passed'])->toBeTrue()
        ->and($body['feedback'])->toContain('Strong on imagery');

    $submission = AssignmentSubmission::first();

    expect($submission->graded_by)->toBe($this->owner->id)
        ->and($submission->graded_at)->not->toBeNull();
});

/* A typo must not award more than the assignment is worth. */
it('clamps a mark to the points available', function (): void {
    $body = $this->actingAs($this->owner)->postJson($this->url, ['points' => 9999])
        ->assertOk()->json('data');

    expect((float) $body['points_earned'])->toBe(50.0);
});

it('marks a submission below the pass mark as not passed', function (): void {
    $body = $this->actingAs($this->owner)->postJson($this->url, ['points' => 10])
        ->assertOk()->json('data');

    expect($body['passed'])->toBeFalse();
});

it('leaves passed unanswered when the assignment has no pass mark', function (): void {
    $this->scenario['assignment']->update(['passing_points' => null]);

    $body = $this->actingAs($this->owner)->postJson($this->url, ['points' => 10])
        ->assertOk()->json('data');

    expect($body)->not->toHaveKey('passed');
});

it('sanitises the feedback on write', function (): void {
    $this->actingAs($this->owner)->postJson($this->url, [
        'points' => 30,
        'feedback' => '<p>Good</p><script>alert(1)</script>',
    ])->assertOk();

    expect(AssignmentSubmission::first()->feedback)
        ->toContain('Good')
        ->not->toContain('<script>');
});

it('shows the learner their mark and feedback', function (): void {
    $this->actingAs($this->owner)->postJson($this->url, [
        'points' => 45,
        'feedback' => '<p>Read more Nazrul.</p>',
    ])->assertOk();

    $body = $this->actingAs($this->scenario['student'])
        ->getJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/assignment")
        ->assertOk()->json('data.submissions.0');

    expect($body['status'])->toBe('graded')
        ->and((float) $body['points_earned'])->toBe(45.0)
        ->and($body['feedback'])->toContain('Read more Nazrul');
});

/*
 * Handing work back is the instructor saying "this one does not count".
 * Charging the learner an attempt for it would make the gesture punitive.
 */
it('re-opens the assignment when work is handed back', function (): void {
    $rules = fn () => $this->actingAs($this->scenario['student'])
        ->getJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/assignment")
        ->json('data.rules');

    // One attempt allowed, and it has been used.
    expect($rules())->toMatchArray(['can_submit' => false, 'reason' => 'no_attempts_left']);

    $this->actingAs($this->owner)->postJson("{$this->url}/return", [
        'feedback' => 'Please cite the text.',
    ])->assertOk()->assertJsonPath('data.status', 'returned');

    expect($rules())->toMatchArray(['can_submit' => true, 'attempts_used' => 0]);

    expect($this->actingAs($this->scenario['student'])
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/assignment/submissions", [
            'body' => 'Now with citations.',
        ])->assertCreated()->json('data.attempt_number'))->toBe(2);
});

it('clears a previous mark when work is handed back', function (): void {
    $this->actingAs($this->owner)->postJson($this->url, ['points' => 40])->assertOk();

    $body = $this->actingAs($this->owner)
        ->postJson("{$this->url}/return", ['feedback' => 'Have another go.'])
        ->assertOk()->json('data');

    expect($body)->not->toHaveKey('points_earned')
        ->and($body['status'])->toBe('returned');
});

it('requires a reason when handing work back', function (): void {
    $this->actingAs($this->owner)->postJson("{$this->url}/return", [])->assertStatus(422);
});

/* A TA grades without being able to author — the role's whole purpose. */
it('lets a course-scoped teaching assistant grade an assignment', function (): void {
    $ta = User::factory()->withRole(RoleKey::Student)->create();
    $ta->assignRole(RoleKey::TeachingAssistant, $this->scenario['course']);

    $this->actingAs($ta->fresh())->postJson($this->url, ['points' => 30])->assertOk();

    $this->actingAs($ta->fresh())
        ->getJson("/api/v1/studio/items/{$this->scenario['item']->uuid}/assignment")
        ->assertStatus(403);
});

it('denies grading to a teaching assistant on another course', function (): void {
    $ta = User::factory()->withRole(RoleKey::Student)->create();
    $ta->assignRole(RoleKey::TeachingAssistant, Course::factory()->published()->create());

    $this->actingAs($ta->fresh())->postJson($this->url, ['points' => 30])->assertStatus(403);
});

it('denies a learner sight of the grading view', function (): void {
    expect($this->actingAs($this->scenario['student'])->getJson($this->url)->assertStatus(403))
        ->toBeApiError('forbidden');
});

it('denies an unrelated instructor sight of a submission', function (): void {
    $stranger = User::factory()->instructor()->create();

    $this->actingAs($stranger)->getJson($this->url)->assertStatus(403);
});
