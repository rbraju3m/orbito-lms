<?php

declare(strict_types=1);

/*
 * Lateness is decided by the SERVER against the assignment's due_at at the
 * moment of submission, and frozen onto the row. The client's clock has no
 * say, and moving the deadline afterwards must not change history.
 */

function lateScenario(array $settings): array
{
    $scenario = assignmentScenario($settings + ['due_at' => now()->subDay()]);
    $scenario['submit'] = "/api/v1/learn/items/{$scenario['item']->uuid}/assignment/submissions";

    return $scenario;
}

it('refuses late work when the policy says reject', function (): void {
    $s = lateScenario(['late_policy' => 'reject']);

    expect($this->actingAs($s['student'])->postJson($s['submit'], ['body' => 'Sorry'])
        ->assertStatus(409))->toBeApiError('submission_rejected');
});

it('says up front that a rejecting assignment can no longer be handed in', function (): void {
    $s = lateScenario(['late_policy' => 'reject']);

    expect($this->actingAs($s['student'])
        ->getJson("/api/v1/learn/items/{$s['item']->uuid}/assignment")
        ->assertOk()->json('data.rules'))
        ->toMatchArray(['can_submit' => false, 'reason' => 'past_due', 'is_past_due' => true]);
});

it('takes late work in full when the policy says accept', function (): void {
    $s = lateScenario(['late_policy' => 'accept']);

    expect($this->actingAs($s['student'])->postJson($s['submit'], ['body' => 'Late but fine'])
        ->assertCreated()->json('data.is_late'))->toBeTrue();
});

it('warns before submitting that the work will count as late', function (): void {
    $s = lateScenario(['late_policy' => 'penalise', 'late_penalty_percent' => 20]);

    expect($this->actingAs($s['student'])
        ->getJson("/api/v1/learn/items/{$s['item']->uuid}/assignment")
        ->assertOk()->json('data.rules'))
        ->toMatchArray([
            'can_submit' => true,
            'will_be_late' => true,
            'late_penalty_percent' => 20,
        ]);
});

it('applies the penalty when the work is graded, not when it is handed in', function (): void {
    $s = lateScenario([
        'late_policy' => 'penalise',
        'late_penalty_percent' => 25,
        'total_points' => 100,
    ]);

    $submission = $this->actingAs($s['student'])
        ->postJson($s['submit'], ['body' => 'Late work'])
        ->assertCreated()->json('data');

    // Nothing has been deducted yet — there is no mark to deduct from.
    expect($submission['is_late'])->toBeTrue()
        ->and($submission)->not->toHaveKey('late_penalty_points');

    $graded = $this->actingAs($s['instructor'])
        ->postJson("/api/v1/studio/grading/assignment/{$submission['id']}", ['points' => 80])
        ->assertOk()->json('data');

    expect((float) $graded['points_raw'])->toBe(80.0)
        ->and((float) $graded['late_penalty_points'])->toBe(20.0)
        ->and((float) $graded['points_earned'])->toBe(60.0);
});

it('deducts nothing from work that was on time', function (): void {
    $s = assignmentScenario([
        'due_at' => now()->addDay(),
        'late_policy' => 'penalise',
        'late_penalty_percent' => 50,
    ]);

    $submission = $this->actingAs($s['student'])
        ->postJson("/api/v1/learn/items/{$s['item']->uuid}/assignment/submissions", ['body' => 'On time'])
        ->assertCreated()->json('data');

    $graded = $this->actingAs($s['instructor'])
        ->postJson("/api/v1/studio/grading/assignment/{$submission['id']}", ['points' => 90])
        ->assertOk()->json('data');

    expect($graded['is_late'])->toBeFalse()
        ->and((float) $graded['late_penalty_points'])->toBe(0.0)
        ->and((float) $graded['points_earned'])->toBe(90.0);
});

it('does not deduct under a policy that merely accepts late work', function (): void {
    $s = lateScenario(['late_policy' => 'accept', 'late_penalty_percent' => 50]);

    $submission = $this->actingAs($s['student'])
        ->postJson($s['submit'], ['body' => 'Late'])->assertCreated()->json('data');

    $graded = $this->actingAs($s['instructor'])
        ->postJson("/api/v1/studio/grading/assignment/{$submission['id']}", ['points' => 70])
        ->assertOk()->json('data');

    expect($graded['is_late'])->toBeTrue()
        ->and((float) $graded['points_earned'])->toBe(70.0);
});

it('does not make earlier work late by moving the deadline afterwards', function (): void {
    $s = assignmentScenario(['due_at' => now()->addDay(), 'late_policy' => 'accept']);

    $submission = $this->actingAs($s['student'])
        ->postJson("/api/v1/learn/items/{$s['item']->uuid}/assignment/submissions", ['body' => 'On time'])
        ->assertCreated()->json('data');

    expect($submission['is_late'])->toBeFalse();

    $s['assignment']->update(['due_at' => now()->subWeek()]);

    expect($this->actingAs($s['student'])
        ->getJson("/api/v1/learn/items/{$s['item']->uuid}/assignment")
        ->json('data.submissions.0.is_late'))->toBeFalse();
});
