<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\CompletionMode;
use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Models\LessonNote;

beforeEach(function (): void {
    seedRegistry();
    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [2]);
    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    $this->enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);
    $this->item = $this->course->items()->orderBy('position')->first();
});

it('marks an item complete and returns the updated progress', function (): void {
    $response = $this->actingAs($this->student)
        ->postJson("/api/v1/learn/items/{$this->item->uuid}/complete")
        ->assertOk();

    expect($response->json('data.completed_items'))->toBe(1)
        ->and((float) $response->json('data.percent'))->toBe(50.0);
});

it('un-marks an item', function (): void {
    $this->actingAs($this->student)->postJson("/api/v1/learn/items/{$this->item->uuid}/complete");

    $response = $this->actingAs($this->student)
        ->deleteJson("/api/v1/learn/items/{$this->item->uuid}/complete")
        ->assertOk();

    expect($response->json('data.completed_items'))->toBe(0);
});

/* Progress belongs to an enrollment; there is nothing to record without one. */
it('refuses to record progress for someone not enrolled', function (): void {
    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    expect($this->actingAs($stranger)
        ->postJson("/api/v1/learn/items/{$this->item->uuid}/complete")
        ->assertStatus(423))->toBeApiError('content_locked');
});

it('refuses to record progress for course staff who are not enrolled', function (): void {
    $owner = User::factory()->instructor()->create();
    $course = courseWithCurriculum(Course::factory()->ownedBy($owner)->published()->create(), [1]);

    $this->actingAs($owner)
        ->postJson("/api/v1/learn/items/{$course->items()->first()->uuid}/complete")
        ->assertStatus(423);
});

it('records a watch position', function (): void {
    $this->item->update(['duration_seconds' => 600]);

    $response = $this->actingAs($this->student)
        ->postJson("/api/v1/learn/items/{$this->item->uuid}/watch", ['position_seconds' => 200])
        ->assertOk();

    expect($response->json('data.watch_position_seconds'))->toBe(200);
});

it('rejects a negative watch position', function (): void {
    $this->actingAs($this->student)
        ->postJson("/api/v1/learn/items/{$this->item->uuid}/watch", ['position_seconds' => -5])
        ->assertStatus(422);
});

it('lets a learner finish a flexible course before completing every item', function (): void {
    $response = $this->actingAs($this->student)
        ->postJson("/api/v1/learn/courses/{$this->course->uuid}/complete")
        ->assertOk();

    expect($response->json('data.is_complete'))->toBeTrue();
});

/* Strict mode means strict: that is what the setting is for. */
it('refuses to finish a strict course with items outstanding', function (): void {
    $this->course->update(['completion_mode' => CompletionMode::Strict]);

    expect($this->actingAs($this->student)
        ->postJson("/api/v1/learn/courses/{$this->course->uuid}/complete")
        ->assertStatus(422))->toBeApiError('course_not_complete');
});

it('completes a strict course once everything is done', function (): void {
    $this->course->update(['completion_mode' => CompletionMode::Strict]);

    foreach ($this->course->items as $item) {
        $this->actingAs($this->student)->postJson("/api/v1/learn/items/{$item->uuid}/complete")->assertOk();
    }

    expect($this->enrollment->fresh('progress')->progress->completed_at)->not->toBeNull();
});

it('resets progress when the course allows it', function (): void {
    $this->actingAs($this->student)->postJson("/api/v1/learn/items/{$this->item->uuid}/complete");

    $response = $this->actingAs($this->student)
        ->postJson("/api/v1/learn/courses/{$this->course->uuid}/reset-progress")
        ->assertOk();

    expect($response->json('data.completed_items'))->toBe(0)
        ->and($response->json('data.is_complete'))->toBeFalse();
});

/*
 * 409, not 404: the endpoint exists and the learner may call it — the course
 * has switched the capability off. Hiding it behind "not found" left the SPA
 * unable to tell a disabled feature from a broken URL.
 */
it('refuses a reset when the course forbids it', function (): void {
    $this->course->setting->update(['reset_progress_allowed' => false]);

    expect($this->actingAs($this->student)
        ->postJson("/api/v1/learn/courses/{$this->course->uuid}/reset-progress")
        ->assertStatus(409))->toBeApiError('progress_rejected');
});

describe('notes', function (): void {
    it('saves and lists a note against a lesson', function (): void {
        $this->actingAs($this->student)->postJson("/api/v1/learn/items/{$this->item->uuid}/notes", [
            'body' => 'Metre is syllable-counted here.',
            'video_timestamp_seconds' => 95,
        ])->assertCreated();

        $response = $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->item->uuid}/notes")
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.video_timestamp_seconds'))->toBe(95);
    });

    it('refuses notes on content the learner cannot open', function (): void {
        $stranger = User::factory()->withRole(RoleKey::Student)->create();

        $this->actingAs($stranger)
            ->postJson("/api/v1/learn/items/{$this->item->uuid}/notes", ['body' => 'Sneaky'])
            ->assertStatus(423);
    });

    it('shows a learner only their own notes', function (): void {
        $other = User::factory()->withRole(RoleKey::Student)->create();
        Enrollment::factory()->create(['course_id' => $this->course->id, 'user_id' => $other->id]);

        $this->actingAs($other)->postJson("/api/v1/learn/items/{$this->item->uuid}/notes", [
            'body' => 'Someone else note',
        ])->assertCreated();

        expect($this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->item->uuid}/notes")->assertOk()->json('data'))
            ->toBe([]);
    });

    /* 404, not 403: the endpoint must not confirm someone else's note exists. */
    it('will not let one learner delete another note', function (): void {
        $other = User::factory()->withRole(RoleKey::Student)->create();
        Enrollment::factory()->create(['course_id' => $this->course->id, 'user_id' => $other->id]);

        $this->actingAs($other)->postJson("/api/v1/learn/items/{$this->item->uuid}/notes", [
            'body' => 'Private',
        ])->assertCreated();

        $note = LessonNote::firstOrFail();

        $this->actingAs($this->student)->deleteJson("/api/v1/learn/notes/{$note->id}")->assertNotFound();
        expect(LessonNote::count())->toBe(1);
    });
});

describe('curriculum changes', function (): void {
    /* Adding a lesson changes every enrolled learner's denominator. */
    it('recounts enrolled learners when a lesson is added', function (): void {
        expect($this->enrollment->fresh('progress')->progress->total_items)->toBe(2);

        $instructor = $this->course->owner;
        $section = $this->course->sections()->first();

        $this->actingAs($instructor)->postJson("/api/v1/studio/courses/{$this->course->uuid}/items", [
            'section_id' => $section->id,
            'type' => 'lesson',
            'title' => 'A new lesson',
        ])->assertCreated();

        expect($this->enrollment->fresh('progress')->progress->total_items)->toBe(3);
    });

    it('keeps the percentage honest when an item is removed', function (): void {
        $this->actingAs($this->student)->postJson("/api/v1/learn/items/{$this->item->uuid}/complete");
        expect((float) $this->enrollment->fresh('progress')->progress->percent)->toBe(50.0);

        // reorder(), not orderByDesc(): the items() relation already applies
        // an ORDER BY, and a second one is appended rather than replacing it —
        // so this would otherwise return the FIRST item, not the last.
        $other = $this->course->items()->reorder('position', 'desc')->first();
        $this->actingAs($this->course->owner)
            ->deleteJson("/api/v1/studio/items/{$other->uuid}")
            ->assertNoContent();

        // One of two done becomes one of one.
        expect((float) $this->enrollment->fresh('progress')->progress->percent)->toBe(100.0);
    });
});
