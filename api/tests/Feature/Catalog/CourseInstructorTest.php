<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;

beforeEach(fn () => seedRegistry());

it('adds a co-instructor', function (): void {
    $owner = User::factory()->instructor()->create();
    $co = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $this->actingAs($owner)->postJson("/api/v1/studio/courses/{$course->uuid}/instructors", [
        'user_id' => $co->uuid,
    ])->assertCreated();

    expect($course->fresh()->instructors)->toHaveCount(2);
});

it('refuses to add a second owner', function (): void {
    $owner = User::factory()->instructor()->create();
    $co = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $this->actingAs($owner)->postJson("/api/v1/studio/courses/{$course->uuid}/instructors", [
        'user_id' => $co->uuid,
        'role' => 'owner',
    ])->assertStatus(422);
});

it('refuses to remove the owner', function (): void {
    $owner = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    // Removing the owner would leave the course unmanageable.
    $this->actingAs($owner)
        ->deleteJson("/api/v1/studio/courses/{$course->uuid}/instructors/{$owner->uuid}")
        ->assertStatus(409);

    expect($course->fresh()->instructors)->toHaveCount(1);
});

it('removes a co-instructor', function (): void {
    $owner = User::factory()->instructor()->create();
    $co = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $this->actingAs($owner)->postJson("/api/v1/studio/courses/{$course->uuid}/instructors", [
        'user_id' => $co->uuid,
    ])->assertCreated();

    $this->actingAs($owner)
        ->deleteJson("/api/v1/studio/courses/{$course->uuid}/instructors/{$co->uuid}")
        ->assertNoContent();

    expect($course->fresh()->instructors)->toHaveCount(1);
});

it('denies instructor management to a stranger', function (): void {
    $owner = User::factory()->instructor()->create();
    $stranger = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $this->actingAs($stranger)->postJson("/api/v1/studio/courses/{$course->uuid}/instructors", [
        'user_id' => $stranger->uuid,
    ])->assertStatus(403);
});

it('updates course settings', function (): void {
    $owner = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $this->actingAs($owner)->patchJson("/api/v1/studio/courses/{$course->uuid}/settings", [
        'enable_qa' => false,
        'enable_certificate' => true,
        'max_students' => 250,
    ])->assertOk()->assertJsonPath('data.max_students', 250);

    $setting = $course->fresh()->setting;
    expect($setting->enable_qa)->toBeFalse()
        ->and($setting->enable_certificate)->toBeTrue();
});

it('rejects an out-of-range completion threshold', function (): void {
    $owner = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $this->actingAs($owner)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}/settings", ['video_completion_threshold' => 150])
        ->assertStatus(422);
});

it('denies settings changes to a stranger', function (): void {
    $owner = User::factory()->instructor()->create();
    $stranger = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $this->actingAs($stranger)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}/settings", ['enable_qa' => false])
        ->assertStatus(403);
});

/*
 * An id that merely EXISTS is not enough: pointing a course thumbnail at
 * someone else's file would leak it through the course page.
 */
it('refuses a thumbnail belonging to another user', function (): void {
    $owner = User::factory()->instructor()->create();
    $other = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $foreign = Media::factory()->ownedBy($other)->create();

    $this->actingAs($owner)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}", ['thumbnail_media_id' => $foreign->id])
        ->assertStatus(422);
});

it('refuses a thumbnail from the wrong collection', function (): void {
    $owner = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $video = Media::factory()
        ->ownedBy($owner)
        ->forCollection(MediaCollection::LessonVideo)
        ->create();

    $this->actingAs($owner)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}", ['thumbnail_media_id' => $video->id])
        ->assertStatus(422);
});

it('accepts the owner own thumbnail', function (): void {
    $owner = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();
    $thumb = Media::factory()->ownedBy($owner)->create();

    $this->actingAs($owner)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}", ['thumbnail_media_id' => $thumb->id])
        ->assertOk();

    expect($course->fresh()->thumbnail_media_id)->toBe($thumb->id);
});
