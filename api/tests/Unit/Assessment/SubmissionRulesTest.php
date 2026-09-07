<?php

declare(strict_types=1);

use App\Domain\Assessment\Enums\SubmissionStatus;
use App\Domain\Assessment\Models\Assignment;
use App\Domain\Assessment\Models\AssignmentSubmission;
use App\Domain\Assessment\Support\SubmissionRules;
use Illuminate\Support\Collection;

/*
 * The rules the submit button renders and the submit Action enforces. They are
 * the same object precisely so the two cannot disagree.
 */

function rulesFor(array $assignment, array $statuses = []): SubmissionRules
{
    $previous = new Collection(
        collect($statuses)->values()->map(fn (SubmissionStatus $status, int $i) => new AssignmentSubmission([
            'attempt_number' => $i + 1,
            'status' => $status,
        ]))->all()
    );

    return new SubmissionRules(new Assignment($assignment), $previous);
}

it('lets a first attempt through', function (): void {
    expect(rulesFor(['max_attempts' => 2])->canSubmit())->toBeTrue();
});

it('counts attempts that were graded', function (): void {
    $rules = rulesFor(['max_attempts' => 2], [SubmissionStatus::Graded]);

    expect($rules->attemptsUsed())->toBe(1)
        ->and($rules->attemptsLeft())->toBe(1)
        ->and($rules->canSubmit())->toBeTrue();
});

it('refuses once the cap is used up', function (): void {
    $rules = rulesFor(['max_attempts' => 1], [SubmissionStatus::Submitted]);

    expect($rules->canSubmit())->toBeFalse()
        ->and($rules->refusal())->toBe('no_attempts_left');
});

/* Handing work back must not cost the learner an attempt. */
it('does not count a returned attempt', function (): void {
    $rules = rulesFor(['max_attempts' => 1], [SubmissionStatus::Returned]);

    expect($rules->attemptsUsed())->toBe(0)
        ->and($rules->canSubmit())->toBeTrue()
        // ...but the numbering still moves on, so the history reads in order.
        ->and($rules->nextAttemptNumber())->toBe(2);
});

it('never runs out when there is no cap', function (): void {
    $rules = rulesFor(
        ['max_attempts' => null],
        [SubmissionStatus::Graded, SubmissionStatus::Graded, SubmissionStatus::Graded],
    );

    expect($rules->attemptsLeft())->toBeNull()
        ->and($rules->canSubmit())->toBeTrue();
});

it('refuses late work only under a rejecting policy', function (): void {
    $past = ['due_at' => now()->subDay(), 'max_attempts' => 5];

    expect(rulesFor($past + ['late_policy' => 'reject'])->refusal())->toBe('past_due')
        ->and(rulesFor($past + ['late_policy' => 'accept'])->canSubmit())->toBeTrue()
        ->and(rulesFor($past + ['late_policy' => 'penalise'])->canSubmit())->toBeTrue();
});

it('warns that work will count as late before it is handed in', function (): void {
    $rules = rulesFor([
        'due_at' => now()->subHour(),
        'late_policy' => 'penalise',
        'late_penalty_percent' => 10,
        'max_attempts' => 1,
    ]);

    expect($rules->toArray())->toMatchArray([
        'can_submit' => true,
        'is_past_due' => true,
        'will_be_late' => true,
        'late_penalty_percent' => 10,
    ]);
});

it('does not call on-time work late', function (): void {
    $rules = rulesFor([
        'due_at' => now()->addDay(),
        'late_policy' => 'penalise',
        'max_attempts' => 1,
    ]);

    expect($rules->toArray()['will_be_late'])->toBeFalse();
});
