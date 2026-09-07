<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseCategory;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Support\UsageCounters;

beforeEach(fn () => seedRegistry());

it('publishes a complete course', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->publishable()->create();

    $this->actingAs($instructor)
        ->postJson("/api/v1/studio/courses/{$course->uuid}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    $fresh = $course->fresh();
    expect($fresh->status)->toBe(CourseStatus::Published)
        ->and($fresh->published_at)->not->toBeNull();
});

/*
 * The failure body IS the publish checklist the Studio renders, so the rules
 * the user sees and the rules the server enforces cannot drift apart.
 */
it('refuses to publish an incomplete course and says exactly why', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->incomplete()->create();

    $response = $this->actingAs($instructor)
        ->postJson("/api/v1/studio/courses/{$course->uuid}/publish")
        ->assertStatus(422);

    expect($response)->toBeApiError('course_not_publishable');

    $codes = collect($response->json('error.details'))->pluck('code');
    expect($codes)->toContain('description_present', 'category_present')
        ->and($course->fresh()->status)->toBe(CourseStatus::Draft);
});

it('keeps published_at from the first publish across a republish', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->publishable()->published()->create();
    $originalPublishedAt = $course->published_at;

    $this->actingAs($instructor)->postJson("/api/v1/studio/courses/{$course->uuid}/unpublish")->assertOk();
    $this->actingAs($instructor)->postJson("/api/v1/studio/courses/{$course->uuid}/publish")->assertOk();

    // published_at is the course's birthday, not the timestamp of the last edit.
    expect($course->fresh()->published_at?->timestamp)->toBe($originalPublishedAt?->timestamp);
});

it('submits a course for review', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->publishable()->create();

    $this->actingAs($instructor)
        ->postJson("/api/v1/studio/courses/{$course->uuid}/submit-review")
        ->assertOk()
        ->assertJsonPath('data.status', 'in_review');

    expect($course->fresh()->submitted_at)->not->toBeNull();
});

it('lets an admin reject a submission back to draft with a note', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $course = Course::factory()->publishable()->inReview()->create();

    $this->actingAs($admin)->postJson(
        "/api/v1/studio/courses/{$course->uuid}/reject-review",
        ['note' => 'Please expand the description.'],
    )->assertOk()->assertJsonPath('data.status', 'draft');

    $fresh = $course->fresh();
    expect($fresh->review_note)->toBe('Please expand the description.')
        ->and($fresh->submitted_at)->toBeNull();
});

it('archives and restores a course', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->publishable()->published()->create();

    $this->actingAs($instructor)->postJson("/api/v1/studio/courses/{$course->uuid}/archive")
        ->assertOk()->assertJsonPath('data.status', 'archived');
    expect($course->fresh()->archived_at)->not->toBeNull();

    // Archiving is not deletion.
    $this->actingAs($instructor)->postJson("/api/v1/studio/courses/{$course->uuid}/publish")
        ->assertOk()->assertJsonPath('data.status', 'published');
    expect($course->fresh()->archived_at)->toBeNull();
});

it('refuses an illegal transition', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->publishable()->published()->create();

    expect($this->actingAs($instructor)
        ->postJson("/api/v1/studio/courses/{$course->uuid}/submit-review")
        ->assertStatus(409))->toBeApiError('course_transition_rejected');
});

it('exposes the allowed transitions to course staff', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->publishable()->create();

    $transitions = $this->actingAs($instructor)
        ->getJson("/api/v1/studio/courses/{$course->uuid}")
        ->assertOk()
        ->json('data.allowed_transitions');

    expect($transitions)->toContain('in_review', 'published', 'archived');
});

it('maintains published-course usage counters across the lifecycle', function (): void {
    $counters = app(UsageCounters::class);
    $metric = UsageMetric::CoursesPublished;

    $instructor = User::factory()->instructor()->create();

    $this->actingAs($instructor)->postJson('/api/v1/studio/courses', [
        'title' => 'Counter test course',
        'description' => 'A description comfortably longer than the fifty characters the publish checklist requires.',
        'category_id' => CourseCategory::factory()->create()->id,
    ])->assertCreated();

    $course = Course::firstOrFail();

    // The publish checklist requires a curriculum from Phase 5 on.
    $section = CourseSection::factory()->create([
        'course_id' => $course->id,
    ]);
    CourseItem::factory()->inSection($section)->create();

    expect($counters->get($metric, $instructor))->toBe(0);

    $this->actingAs($instructor)->postJson("/api/v1/studio/courses/{$course->uuid}/publish")->assertOk();
    expect($counters->get($metric, $instructor))->toBe(1)
        ->and($counters->get($metric))->toBe(1);

    $this->actingAs($instructor)->postJson("/api/v1/studio/courses/{$course->uuid}/archive")->assertOk();
    expect($counters->get($metric, $instructor))->toBe(0)
        ->and($counters->get($metric))->toBe(0);
});
