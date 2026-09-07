<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SetCoursePrerequisites;
use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(function (): void {
    seedRegistry();

    $this->intro = courseWithCurriculum(Course::factory()->published()->create(['title' => 'Intro']), [1]);
    $this->advanced = courseWithCurriculum(Course::factory()->published()->create(['title' => 'Advanced']), [1]);
    $this->student = User::factory()->withRole(RoleKey::Student)->create();

    app(SetCoursePrerequisites::class)->handle($this->advanced, [$this->intro->id]);
});

it('refuses enrolment while a prerequisite is outstanding, and names it', function (): void {
    $response = $this->actingAs($this->student)
        ->postJson("/api/v1/courses/{$this->advanced->uuid}/enroll")
        ->assertStatus(409);

    expect($response)->toBeApiError('enrollment_rejected')
        ->and($response->json('error.message'))->toContain('Intro')
        ->and($response->json('error.meta.prerequisites.0.title'))->toBe('Intro');

    expect(Enrollment::where('course_id', $this->advanced->id)->count())->toBe(0);
});

it('allows enrolment once the prerequisite is completed', function (): void {
    Enrollment::factory()->create([
        'course_id' => $this->intro->id,
        'user_id' => $this->student->id,
        'status' => EnrollmentStatus::Completed,
        'completed_at' => now(),
    ]);

    $this->actingAs($this->student)
        ->postJson("/api/v1/courses/{$this->advanced->uuid}/enroll")
        ->assertCreated();
});

/* Merely being enrolled in the prerequisite is not finishing it. */
it('does not accept an in-progress prerequisite', function (): void {
    Enrollment::factory()->create([
        'course_id' => $this->intro->id,
        'user_id' => $this->student->id,
        'status' => EnrollmentStatus::Active,
    ]);

    $this->actingAs($this->student)
        ->postJson("/api/v1/courses/{$this->advanced->uuid}/enroll")
        ->assertStatus(409);
});

/*
 * The decision the plan settled: prerequisites gate ENROLMENT, not ongoing
 * access. Adding one to a live course must not evict the people inside it.
 */
it('never locks out someone already enrolled', function (): void {
    $other = courseWithCurriculum(Course::factory()->published()->create(['title' => 'Statistics']), [1]);

    $enrollment = Enrollment::factory()->create([
        'course_id' => $this->advanced->id,
        'user_id' => $this->student->id,
    ]);

    app(SetCoursePrerequisites::class)->handle($this->advanced, [$this->intro->id, $other->id]);

    $item = $this->advanced->items()->first();

    $this->actingAs($this->student)
        ->getJson("/api/v1/learn/items/{$item->uuid}")
        ->assertOk();
});

it('renders each prerequisite with whether the viewer has met it', function (): void {
    Enrollment::factory()->create([
        'course_id' => $this->intro->id,
        'user_id' => $this->student->id,
        'status' => EnrollmentStatus::Completed,
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->advanced->slug}")
        ->assertOk();

    expect($response->json('data.prerequisites'))->toHaveCount(1)
        ->and($response->json('data.prerequisites.0.title'))->toBe('Intro')
        ->and($response->json('data.prerequisites.0.is_met'))->toBeTrue();
});

/*
 * The catalogue is members-only, so the reader here is a member of the academy
 * who has simply not finished anything — which is the state the disabled
 * enrol button has to explain.
 */
it('shows a member who has completed nothing every prerequisite as unmet', function (): void {
    $response = $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->advanced->slug}")->assertOk();

    expect($response->json('data.prerequisites'))->toHaveCount(1)
        ->and($response->json('data.prerequisites.0.is_met'))->toBeFalse();
});

it('refuses the course page to an anonymous caller', function (): void {
    $this->getJson("/api/v1/courses/{$this->advanced->slug}")->assertStatus(401);
});

describe('setting them', function (): void {
    beforeEach(function (): void {
        $this->owner = User::factory()->instructor()->create();
        $this->course = Course::factory()->ownedBy($this->owner)->create();
    });

    it('replaces the whole set', function (): void {
        $this->actingAs($this->owner)
            ->putJson("/api/v1/studio/courses/{$this->course->uuid}/prerequisites", [
                'course_ids' => [$this->intro->id, $this->advanced->id],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/studio/courses/{$this->course->uuid}/prerequisites", [
                'course_ids' => [$this->intro->id],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('clears them with an empty list', function (): void {
        app(SetCoursePrerequisites::class)->handle($this->course, [$this->intro->id]);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/studio/courses/{$this->course->uuid}/prerequisites", ['course_ids' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('refuses a self-reference', function (): void {
        expect($this->actingAs($this->owner)
            ->putJson("/api/v1/studio/courses/{$this->course->uuid}/prerequisites", [
                'course_ids' => [$this->course->id],
            ])
            ->assertStatus(422))->toBeApiError('prerequisite_rejected');
    });

    /* A needs B and B needs A is a pair of courses nobody can ever enter. */
    it('refuses a cycle', function (): void {
        app(SetCoursePrerequisites::class)->handle($this->intro, [$this->course->id]);

        expect($this->actingAs($this->owner)
            ->putJson("/api/v1/studio/courses/{$this->course->uuid}/prerequisites", [
                'course_ids' => [$this->intro->id],
            ])
            ->assertStatus(422))->toBeApiError('prerequisite_rejected');
    });

    it('refuses a cycle two hops away', function (): void {
        $middle = Course::factory()->published()->create();

        app(SetCoursePrerequisites::class)->handle($this->intro, [$middle->id]);
        app(SetCoursePrerequisites::class)->handle($middle, [$this->course->id]);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/studio/courses/{$this->course->uuid}/prerequisites", [
                'course_ids' => [$this->intro->id],
            ])
            ->assertStatus(422);
    });

    it('refuses more than ten', function (): void {
        $ids = Course::factory()->count(11)->create()->pluck('id')->all();

        $this->actingAs($this->owner)
            ->putJson("/api/v1/studio/courses/{$this->course->uuid}/prerequisites", ['course_ids' => $ids])
            ->assertStatus(422);
    });

    it('denies someone with no claim on the course', function (): void {
        $stranger = User::factory()->instructor()->create();

        $this->actingAs($stranger)
            ->putJson("/api/v1/studio/courses/{$this->course->uuid}/prerequisites", [
                'course_ids' => [$this->intro->id],
            ])
            ->assertForbidden();
    });

    it('rejects a non-numeric list', function (): void {
        $this->actingAs($this->owner)
            ->putJson("/api/v1/studio/courses/{$this->course->uuid}/prerequisites", [
                'course_ids' => ['not-an-id'],
            ])
            ->assertStatus(422);
    });
});
