<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Enums\DripMode;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Enums\ItemProgressStatus;
use App\Domain\Progress\Models\ItemProgress;

/*
 * The read path was gated from Phase 6; the WRITE path resolved access at
 * course level, so a learner could complete — or watch their way through — a
 * lesson drip had not released, by POSTing to it without ever reading it.
 * Every one of these is a regression test for that gap.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [2]);
    $this->course->setting->update(['drip_mode' => DripMode::Sequential]);

    [$this->first, $this->second] = $this->course->items()->orderBy('position')->get()->all();

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    $this->enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);
});

it('refuses to mark a locked item complete', function (): void {
    expect($this->actingAs($this->student)
        ->postJson("/api/v1/learn/items/{$this->second->uuid}/complete")
        ->assertStatus(423))->toBeApiError('content_locked');

    expect(ItemProgress::where('course_item_id', $this->second->id)->exists())->toBeFalse();
});

it('refuses to record a watch position on a locked item', function (): void {
    $this->actingAs($this->student)
        ->postJson("/api/v1/learn/items/{$this->second->uuid}/watch", ['position_seconds' => 30])
        ->assertStatus(423);
});

it('refuses to un-complete a locked item', function (): void {
    $this->actingAs($this->student)
        ->deleteJson("/api/v1/learn/items/{$this->second->uuid}/complete")
        ->assertStatus(423);
});

it('allows all three once the item unlocks', function (): void {
    $this->actingAs($this->student)
        ->postJson("/api/v1/learn/items/{$this->first->uuid}/complete")
        ->assertOk();

    $this->actingAs($this->student)
        ->postJson("/api/v1/learn/items/{$this->second->uuid}/complete")
        ->assertOk();

    expect(ItemProgress::where('course_item_id', $this->second->id)->first()->status)
        ->toBe(ItemProgressStatus::Completed);
});

/*
 * Quiz-start resolves access through CourseAccess (ADR-03), so it inherits
 * drip without a line of its own. This pins that it actually does.
 */
it('refuses to start a quiz that drip has not released', function (): void {
    $scenario = quizScenario();
    $course = $scenario['course'];
    $quizItem = $scenario['item'];

    // A lesson for the quiz to wait on, placed before it.
    $quizItem->update(['position' => 5]);
    $lesson = courseWithCurriculum($course, [1])->items()
        ->where('id', '!=', $quizItem->id)->orderBy('position')->first();

    $course->setting()->firstOrCreate(['course_id' => $course->id])
        ->update(['drip_mode' => DripMode::Sequential]);

    $quizItem->update(['drip_after_item_id' => $lesson->id]);

    expect($this->actingAs($scenario['student'])
        ->postJson("/api/v1/learn/items/{$quizItem->uuid}/quiz/attempts")
        ->assertStatus(423))->toBeApiError('content_locked');
});
