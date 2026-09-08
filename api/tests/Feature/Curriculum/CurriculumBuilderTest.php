<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Quiz;
use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Curriculum\Models\Lesson;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;

beforeEach(function (): void {
    seedRegistry();
    $this->instructor = User::factory()->instructor()->create();
    $this->course = Course::factory()->ownedBy($this->instructor)->withCategory()->create();
});

it('creates a section', function (): void {
    $this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/courses/{$this->course->uuid}/sections", ['title' => 'Getting started'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Getting started');

    expect($this->course->sections()->count())->toBe(1);
});

it('adds a lesson to a section', function (): void {
    $section = CourseSection::factory()->create(['course_id' => $this->course->id]);

    $this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/courses/{$this->course->uuid}/items", [
            'section_id' => $section->id,
            'type' => 'lesson',
            'title' => 'What is metre?',
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'lesson')
        ->assertJsonPath('data.title', 'What is metre?');

    expect(Lesson::count())->toBe(1);
});

/*
 * The spine declared every future item type from Phase 5 and gated creation on
 * `isAvailable()`. As of P15 they all exist, so what this now guards is the
 * OTHER half of that design: a type the enum has never heard of is still
 * refused at the edge rather than reaching the itemable factory.
 */
it('refuses an item type the spine has never heard of', function (): void {
    $section = CourseSection::factory()->create(['course_id' => $this->course->id]);

    expect($this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/courses/{$this->course->uuid}/items", [
            'section_id' => $section->id,
            'type' => 'seminar',
            'title' => 'Not a thing',
        ])
        ->assertStatus(422))->toBeApiError('validation_failed');
});

it('creates a live session as a placeholder with no link', function (): void {
    $section = CourseSection::factory()->create(['course_id' => $this->course->id]);

    $response = $this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/courses/{$this->course->uuid}/items", [
            'section_id' => $section->id,
            'type' => 'live_session',
            'title' => 'Week 1 call',
        ])
        ->assertCreated();

    $session = LiveSession::query()->sole();

    /*
     * Deliberately no join URL. The learner's pane says the link has not been
     * added rather than offering a dead button — the same treatment a lesson
     * with no body gets.
     */
    expect($session->join_url)->toBeNull()
        ->and($session->title)->toBe('Week 1 call')
        // The course owner hosts by default; the author can change it.
        ->and($session->host_id)->toBe($this->course->owner_id)
        ->and($response->json('data.type'))->toBe('live_session');
});

it('creates an assignment item now that the assignment entity exists', function (): void {
    $section = CourseSection::factory()->create(['course_id' => $this->course->id]);

    $response = $this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/courses/{$this->course->uuid}/items", [
            'section_id' => $section->id,
            'type' => 'assignment',
            'title' => 'Close reading',
        ])->assertCreated();

    expect($response->json('data.type'))->toBe('assignment');
    $this->assertDatabaseCount('assignments', 1);
});

it('creates a quiz item now that the quiz entity exists', function (): void {
    $section = CourseSection::factory()->create(['course_id' => $this->course->id]);

    $this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/courses/{$this->course->uuid}/items", [
            'section_id' => $section->id,
            'type' => 'quiz',
            'title' => 'Chapter quiz',
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'quiz');

    expect(Quiz::count())->toBe(1);
});

it('refuses a section belonging to another course', function (): void {
    $foreign = CourseSection::factory()->create();

    expect($this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/courses/{$this->course->uuid}/items", [
            'section_id' => $foreign->id,
            'type' => 'lesson',
            'title' => 'Wrong course',
        ])
        ->assertStatus(409))->toBeApiError('curriculum_rejected');
});

it('renames an item inline', function (): void {
    $course = courseWithCurriculum($this->course, [1]);
    $item = $course->items()->first();

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/items/{$item->uuid}", ['title' => 'Renamed inline'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed inline');
});

it('toggles the preview flag', function (): void {
    $course = courseWithCurriculum($this->course, [1]);
    $item = $course->items()->first();

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/items/{$item->uuid}", ['is_preview' => true])
        ->assertOk()
        ->assertJsonPath('data.is_preview', true);
});

it('saves lesson content and video', function (): void {
    $course = courseWithCurriculum($this->course, [1]);
    $item = $course->items()->first();

    $this->actingAs($this->instructor)->patchJson("/api/v1/studio/items/{$item->uuid}/lesson", [
        'content' => '<p>Bengali metre is syllable-counted.</p>',
        'video_provider' => 'youtube',
        'video_url' => 'https://www.youtube.com/watch?v=abc123',
        'video_duration_seconds' => 420,
    ])->assertOk()->assertJsonPath('data.content.video_provider', 'youtube');

    // The item's duration is what the curriculum and progress read.
    expect($item->fresh()->duration_seconds)->toBe(420);
});

/*
 * Switching provider must not leave a stale media id pointing at a file the
 * lesson no longer shows.
 */
it('clears the unused video field when the provider changes', function (): void {
    $course = courseWithCurriculum($this->course, [1]);
    $item = $course->items()->first();

    $this->actingAs($this->instructor)->patchJson("/api/v1/studio/items/{$item->uuid}/lesson", [
        'video_provider' => 'youtube',
        'video_url' => 'https://youtube.com/watch?v=one',
    ])->assertOk();

    $this->actingAs($this->instructor)->patchJson("/api/v1/studio/items/{$item->uuid}/lesson", [
        'video_provider' => 'none',
    ])->assertOk();

    $lesson = $item->fresh()->itemable;
    expect($lesson->video_url)->toBeNull()
        ->and($lesson->video_media_id)->toBeNull()
        ->and($lesson->video_duration_seconds)->toBe(0);
});

it('requires a URL when the provider needs one', function (): void {
    $course = courseWithCurriculum($this->course, [1]);
    $item = $course->items()->first();

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/items/{$item->uuid}/lesson", ['video_provider' => 'youtube'])
        ->assertStatus(422);
});

it('refuses a video file belonging to another user', function (): void {
    $course = courseWithCurriculum($this->course, [1]);
    $item = $course->items()->first();
    $stranger = User::factory()->instructor()->create();

    $foreign = Media::factory()
        ->ownedBy($stranger)
        ->forCollection(MediaCollection::LessonVideo)
        ->create();

    $this->actingAs($this->instructor)->patchJson("/api/v1/studio/items/{$item->uuid}/lesson", [
        'video_provider' => 'upload',
        'video_media_id' => $foreign->id,
    ])->assertStatus(422);
});

it('deletes an item and closes the position gap', function (): void {
    $course = courseWithCurriculum($this->course, [3]);
    $items = $course->items()->orderBy('position')->get();

    $this->actingAs($this->instructor)
        ->deleteJson("/api/v1/studio/items/{$items[1]->uuid}")
        ->assertNoContent();

    $positions = CourseItem::where('course_id', $course->id)->orderBy('position')->pluck('position');
    expect($positions->all())->toBe([0, 1]);
});

it('deletes a section with its items', function (): void {
    $course = courseWithCurriculum($this->course, [2, 2]);
    $section = $course->sections()->first();

    $this->actingAs($this->instructor)
        ->deleteJson("/api/v1/studio/sections/{$section->id}")
        ->assertNoContent();

    expect(CourseItem::where('course_id', $course->id)->count())->toBe(2)
        ->and(CourseSection::where('course_id', $course->id)->count())->toBe(1);
});

/* A duplicate starts unpublished: silently adding a live item is a surprise. */
it('duplicates an item as an unpublished draft', function (): void {
    $course = courseWithCurriculum($this->course, [1]);
    $item = $course->items()->first();

    $response = $this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/items/{$item->uuid}/duplicate")
        ->assertCreated();

    expect($response->json('data.title'))->toContain('(copy)')
        ->and($response->json('data.is_published'))->toBeFalse()
        ->and(CourseItem::where('course_id', $course->id)->count())->toBe(2);
});

it('duplicates a section with all of its items', function (): void {
    $course = courseWithCurriculum($this->course, [3]);
    $section = $course->sections()->first();

    $this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/sections/{$section->id}/duplicate")
        ->assertCreated();

    expect(CourseSection::where('course_id', $course->id)->count())->toBe(2)
        ->and(CourseItem::where('course_id', $course->id)->count())->toBe(6);
});

it('returns the curriculum tree in two queries regardless of size', function (): void {
    $course = courseWithCurriculum($this->course, [4, 4, 4]);

    DB::enableQueryLog();
    $this->actingAs($this->instructor)
        ->getJson("/api/v1/studio/courses/{$course->uuid}/curriculum")
        ->assertOk();
    $queries = collect(DB::getQueryLog())
        ->filter(fn ($q) => str_contains($q['query'], 'course_sections') || str_contains($q['query'], 'course_items'));
    DB::disableQueryLog();

    // Sections, then every item in one go — not one query per section.
    expect($queries)->toHaveCount(2);
});

it('denies curriculum reads to an unrelated instructor', function (): void {
    $stranger = User::factory()->instructor()->create();
    $course = courseWithCurriculum($this->course, [1]);

    $this->actingAs($stranger)
        ->getJson("/api/v1/studio/courses/{$course->uuid}/curriculum")
        ->assertStatus(403);
});

it('maintains the denormalised course counters', function (): void {
    $course = courseWithCurriculum($this->course, [2, 3]);

    $fresh = $course->fresh();
    expect($fresh->section_count)->toBe(2)
        ->and($fresh->item_count)->toBe(5);

    $this->actingAs($this->instructor)
        ->deleteJson("/api/v1/studio/items/{$course->items()->first()->uuid}")
        ->assertNoContent();

    expect($course->fresh()->item_count)->toBe(4);
});

it('excludes unpublished items from the counters', function (): void {
    $course = courseWithCurriculum($this->course, [3]);
    $item = $course->items()->first();

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/items/{$item->uuid}", ['is_published' => false])
        ->assertOk();

    expect($course->fresh()->item_count)->toBe(2);
});
