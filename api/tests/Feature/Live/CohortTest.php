<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Models\Cohort;
use App\Domain\Live\Models\LiveSession;

beforeEach(function (): void {
    seedRegistry();

    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->published()->create(),
        [1],
    );
    $this->student = User::factory()->withRole(RoleKey::Student)->create();
});

it('hides a draft run from learners and shows it to staff', function (): void {
    Cohort::factory()->create(['course_id' => $this->course->id]);

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/cohorts")
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.can_manage', false);

    $this->actingAs($this->instructor)
        ->getJson("/api/v1/courses/{$this->course->uuid}/cohorts")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.can_manage', true);
});

it('enrols into a run and records which one', function (): void {
    $cohort = Cohort::factory()->open()->create(['course_id' => $this->course->id]);

    $this->actingAs($this->student)
        ->postJson("/api/v1/cohorts/{$cohort->uuid}/join")
        ->assertCreated();

    $enrollment = Enrollment::query()->where('user_id', $this->student->id)->sole();

    expect($enrollment->cohort_id)->toBe($cohort->id)
        ->and($enrollment->course_id)->toBe($this->course->id);
});

it('refuses a run that is not open', function (): void {
    $cohort = Cohort::factory()->create(['course_id' => $this->course->id]);

    $this->actingAs($this->student)
        ->postJson("/api/v1/cohorts/{$cohort->uuid}/join")
        ->assertStatus(409);
});

it('refuses a run past its deadline', function (): void {
    /*
     * A separate question from the status: an academy may want a cohort
     * listed and closed while it decides, which is not the same as the
     * deadline having passed.
     */
    $cohort = Cohort::factory()->open()->create([
        'course_id' => $this->course->id,
        'enrollment_deadline' => now()->subDay(),
    ]);

    $this->actingAs($this->student)
        ->postJson("/api/v1/cohorts/{$cohort->uuid}/join")
        ->assertStatus(409);

    expect($cohort->isJoinable())->toBeFalse();
});

it('holds a run to its capacity', function (): void {
    $cohort = Cohort::factory()->open()->withCapacity(1)->create(['course_id' => $this->course->id]);

    $this->actingAs($this->student)
        ->postJson("/api/v1/cohorts/{$cohort->uuid}/join")
        ->assertCreated();

    $second = User::factory()->withRole(RoleKey::Student)->create();

    /*
     * Counted and inserted in ONE transaction behind the cohort's row lock —
     * the identical race the course seat limit was fixed for in Phase 9.
     */
    $this->actingAs($second)
        ->postJson("/api/v1/cohorts/{$cohort->uuid}/join")
        ->assertStatus(409);

    expect($cohort->fresh()->placesRemaining())->toBe(0);
});

it('tells uncapped and full apart', function (): void {
    $uncapped = Cohort::factory()->open()->create(['course_id' => $this->course->id]);
    $full = Cohort::factory()->open()->withCapacity(1)->create(['course_id' => $this->course->id]);

    Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'cohort_id' => $full->id,
        'user_id' => $this->student->id,
    ]);

    // Null is not zero, and the UI has to be able to say so.
    expect($uncapped->placesRemaining())->toBeNull()
        ->and($full->fresh()->placesRemaining())->toBe(0);
});

it('still enforces everything ordinary enrolment does', function (): void {
    // Joining a run adds a cohort; it does not open a second door into the
    // course. A paid course is still paid.
    $paid = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->published()->create(['pricing_model' => 'one_time']),
        [1],
    );
    $cohort = Cohort::factory()->open()->create(['course_id' => $paid->id]);

    $this->actingAs($this->student)
        ->postJson("/api/v1/cohorts/{$cohort->uuid}/join")
        ->assertStatus(409);
});

it('deletes a run nobody is using', function (): void {
    $cohort = Cohort::factory()->create(['course_id' => $this->course->id]);

    $this->actingAs($this->instructor)
        ->getJson("/api/v1/courses/{$this->course->uuid}/cohorts")
        ->assertJsonPath('data.0.is_deletable', true);

    $this->actingAs($this->instructor)
        ->deleteJson("/api/v1/cohorts/{$cohort->uuid}")
        ->assertNoContent();

    expect(Cohort::query()->find($cohort->id))->toBeNull();
});

/*
 * Deleting a run cascades its sessions — and their attendance — away, and
 * leaves its learners without the run they joined. One with either is
 * cancelled instead, and the list says so before anybody reaches for delete.
 */
it('refuses to delete a run that has sessions or learners', function (string $what): void {
    $cohort = Cohort::factory()->open()->create(['course_id' => $this->course->id]);

    if ($what === 'a session') {
        LiveSession::factory()->create([
            'course_id' => $this->course->id,
            'cohort_id' => $cohort->id,
            'host_id' => $this->instructor->id,
        ]);
    } else {
        $this->actingAs($this->student)->postJson("/api/v1/cohorts/{$cohort->uuid}/join")->assertCreated();
    }

    $this->actingAs($this->instructor)
        ->getJson("/api/v1/courses/{$this->course->uuid}/cohorts")
        ->assertJsonPath('data.0.is_deletable', false);

    expect($this->actingAs($this->instructor)
        ->deleteJson("/api/v1/cohorts/{$cohort->uuid}")
        ->assertStatus(409))->toBeApiError('cohort_in_use');

    expect(Cohort::query()->find($cohort->id))->not->toBeNull()
        ->and(LiveSession::query()->where('cohort_id', $cohort->id)->count())->toBe($what === 'a session' ? 1 : 0);
})->with(['a session', 'a learner']);

it('refuses to let a stranger delete a run', function (): void {
    $cohort = Cohort::factory()->create(['course_id' => $this->course->id]);

    $this->actingAs(User::factory()->instructor()->create())
        ->deleteJson("/api/v1/cohorts/{$cohort->uuid}")
        ->assertForbidden();

    expect(Cohort::query()->find($cohort->id))->not->toBeNull();
});

it('refuses to let a stranger schedule a run', function (): void {
    $stranger = User::factory()->instructor()->create();

    $this->actingAs($stranger)
        ->postJson("/api/v1/courses/{$this->course->uuid}/cohorts", [
            'name' => 'Not mine',
            'starts_at' => now()->addWeek()->toIso8601String(),
        ])
        ->assertForbidden();
});
