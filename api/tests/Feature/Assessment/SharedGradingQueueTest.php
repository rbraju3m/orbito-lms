<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assignment;
use App\Domain\Assessment\Models\Question;
use App\Domain\Curriculum\Enums\ItemType;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * One list of work waiting for a person, across both kinds. An instructor
 * thinks in terms of "what is there to mark today", not "which table is it in".
 */

beforeEach(function (): void {
    $this->scenario = quizScenario(['passing_score_percent' => 50]);
    $this->owner = $this->scenario['instructor'];
    $this->student = $this->scenario['student'];
    $this->course = $this->scenario['course'];

    // A quiz with an essay in it, submitted and waiting.
    $essay = Question::factory()->longAnswer()->create(['owner_id' => $this->owner->id]);
    attachQuestions($this->scenario['quiz'], [$essay]);

    $attemptId = $this->actingAs($this->student)
        ->postJson("/api/v1/learn/items/{$this->scenario['item']->uuid}/quiz/attempts")
        ->assertCreated()->json('data.attempt.id');

    $this->actingAs($this->student)
        ->patchJson("/api/v1/learn/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $essay->uuid,
            'answer' => ['text' => 'Metre in Bengali verse is counted by syllable.'],
        ])->assertOk();

    $this->actingAs($this->student)
        ->postJson("/api/v1/learn/quiz-attempts/{$attemptId}/submit")->assertOk();

    // An assignment in the same course, also submitted and waiting.
    $assignment = Assignment::create(['total_points' => 20]);
    $section = CourseSection::factory()->create(['course_id' => $this->course->id]);

    $this->assignmentItem = CourseItem::create([
        'course_id' => $this->course->id,
        'section_id' => $section->id,
        'position' => 1,
        'type' => ItemType::Assignment,
        'itemable_type' => $assignment->getMorphClass(),
        'itemable_id' => $assignment->id,
        'title' => 'Close reading',
        'is_published' => true,
    ]);

    $this->actingAs($this->student)
        ->postJson("/api/v1/learn/items/{$this->assignmentItem->uuid}/assignment/submissions", [
            'body' => 'My close reading.',
        ])->assertCreated();

    $this->queue = "/api/v1/studio/courses/{$this->course->uuid}/grading";
});

it('lists quizzes and assignments together', function (): void {
    $body = $this->actingAs($this->owner)->getJson($this->queue)->assertOk();

    expect($body->json('meta.total'))->toBe(2)
        ->and(collect($body->json('data'))->pluck('kind')->sort()->values()->all())
        ->toBe(['assignment', 'quiz']);
});

it('says which one to open, and for whom', function (): void {
    $rows = collect($this->actingAs($this->owner)->getJson($this->queue)->json('data'));

    $assignment = $rows->firstWhere('kind', 'assignment');

    expect($assignment['item']['title'])->toBe('Close reading')
        ->and($assignment['learner']['name'])->toBe($this->student->name)
        ->and($assignment['awaiting_review'])->toBeTrue()
        // ISO-8601 like every other timestamp in the API, not the driver's
        // raw datetime string.
        ->and($assignment['submitted_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');
});

it('serves the person who has been waiting longest first', function (): void {
    // Both were handed in within the same second above, so move one of them
    // clearly into the past rather than relying on how MySQL orders a tie.
    DB::table('assignment_submissions')->update(['submitted_at' => now()->subHour()]);

    $rows = collect($this->actingAs($this->owner)->getJson($this->queue)->json('data'));

    expect($rows->pluck('kind')->all())->toBe(['assignment', 'quiz']);
});

/*
 * `submitted_at` has second precision. Without a tiebreak, two pieces of work
 * handed in together order arbitrarily, and a row can appear on two pages or
 * on none.
 */
it('pages deterministically when two pieces of work share a timestamp', function (): void {
    $at = now()->subMinutes(5);
    DB::table('assignment_submissions')->update(['submitted_at' => $at]);
    DB::table('quiz_attempts')->update(['submitted_at' => $at]);

    $seen = collect(range(1, 2))->flatMap(fn (int $page) => collect(
        $this->actingAs($this->owner)->getJson("{$this->queue}?per_page=1&page={$page}")->json('data')
    )->pluck('id'));

    expect($seen)->toHaveCount(2)
        ->and($seen->unique())->toHaveCount(2);
});

it('drops work once it has been marked', function (): void {
    $submissionId = collect($this->actingAs($this->owner)->getJson($this->queue)->json('data'))
        ->firstWhere('kind', 'assignment')['id'];

    $this->actingAs($this->owner)
        ->postJson("/api/v1/studio/grading/assignment/{$submissionId}", ['points' => 15])
        ->assertOk();

    $body = $this->actingAs($this->owner)->getJson($this->queue)->assertOk();

    expect($body->json('meta.total'))->toBe(1)
        ->and($body->json('data.0.kind'))->toBe('quiz');
});

it('shows everything when asked for all of it', function (): void {
    $submissionId = collect($this->actingAs($this->owner)->getJson($this->queue)->json('data'))
        ->firstWhere('kind', 'assignment')['id'];

    $this->actingAs($this->owner)
        ->postJson("/api/v1/studio/grading/assignment/{$submissionId}", ['points' => 15])
        ->assertOk();

    expect($this->actingAs($this->owner)->getJson("{$this->queue}?status=all")->json('meta.total'))
        ->toBe(2);
});

it('paginates', function (): void {
    $body = $this->actingAs($this->owner)->getJson("{$this->queue}?per_page=1")->assertOk();

    expect($body->json('data'))->toHaveCount(1)
        ->and($body->json('meta.total'))->toBe(2)
        ->and($body->json('meta.last_page'))->toBe(2);
});

/*
 * The queue is filtered by what this reader may actually open, not by a
 * query-string parameter. A grader who cannot mark quizzes must not be shown
 * a row that 403s when they click it.
 */
it('hides the kind a grader is not allowed to open', function (): void {
    // A course-scoped role that can mark essays but not quizzes.
    $role = Role::create([
        'key' => 'essay_marker',
        'name' => 'Essay marker',
        'scope_kind' => 'course',
        'is_system' => false,
    ]);
    $role->permissions()->attach(
        Permission::whereIn('key', ['course.view.unpublished', 'assignment.grade.own'])->pluck('id')
    );

    $marker = User::factory()->withRole(RoleKey::Student)->create();
    $marker->assignRole('essay_marker', $this->course);

    $rows = collect($this->actingAs($marker->fresh())->getJson($this->queue)->assertOk()->json('data'));

    expect($rows->pluck('kind')->unique()->all())->toBe(['assignment']);
});

it('denies the queue to a learner', function (): void {
    expect($this->actingAs($this->student)->getJson($this->queue)->assertStatus(403))
        ->toBeApiError('forbidden');
});
