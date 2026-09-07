<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseCategory;
use App\Domain\Catalog\Models\CourseTag;
use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(fn () => seedRegistry());

it('lets an approved instructor create a course', function (): void {
    $instructor = User::factory()->instructor()->create();

    $response = $this->actingAs($instructor)->postJson('/api/v1/studio/courses', [
        'title' => 'Introduction to Bengali Poetry',
        'subtitle' => 'From Tagore onwards',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Introduction to Bengali Poetry')
        ->assertJsonPath('data.status', 'draft');

    $course = Course::firstOrFail();
    expect($course->owner_id)->toBe($instructor->id)
        ->and($course->detail)->not->toBeNull()
        ->and($course->setting)->not->toBeNull()
        ->and($course->instructors)->toHaveCount(1);
});

it('denies course creation to a student', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();

    expect($this->actingAs($student)->postJson('/api/v1/studio/courses', ['title' => 'Sneaky course'])
        ->assertStatus(403))->toBeApiError('forbidden');
});

it('denies course creation to a pending instructor', function (): void {
    $pending = User::factory()->instructor(InstructorStatus::Pending)->create();

    $this->actingAs($pending)->postJson('/api/v1/studio/courses', ['title' => 'Too early'])
        ->assertStatus(403);
});

it('rejects a course with no title', function (): void {
    $instructor = User::factory()->instructor()->create();

    expect($this->actingAs($instructor)->postJson('/api/v1/studio/courses', [])->assertStatus(422))
        ->toBeApiError('validation_failed');
});

it('generates a unique slug even for duplicate titles', function (): void {
    $instructor = User::factory()->instructor()->create();

    foreach (range(1, 3) as $_) {
        $this->actingAs($instructor)
            ->postJson('/api/v1/studio/courses', ['title' => 'Same Title Everywhere'])
            ->assertCreated();
    }

    expect(Course::pluck('slug')->unique())->toHaveCount(3);
});

it('never lets a client set the owner or the status', function (): void {
    $instructor = User::factory()->instructor()->create();
    $victim = User::factory()->instructor()->create();

    $this->actingAs($instructor)->postJson('/api/v1/studio/courses', [
        'title' => 'Ownership test',
        'owner_id' => $victim->id,
        'status' => 'published',
    ])->assertCreated();

    $course = Course::firstOrFail();
    expect($course->owner_id)->toBe($instructor->id)
        ->and($course->status)->toBe(CourseStatus::Draft);
});

it('updates only the fields the request actually sends', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->create([
        'subtitle' => 'Keep me',
        'description' => 'Keep me too, this is a perfectly good description of a course.',
    ]);

    $this->actingAs($instructor)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}", ['title' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed');

    // A PATCH that omits a field must leave it alone, not null it.
    $fresh = $course->fresh();
    expect($fresh->subtitle)->toBe('Keep me')
        ->and($fresh->description)->toContain('Keep me too');
});

it('can explicitly clear a nullable field', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->create(['subtitle' => 'Remove me']);

    $this->actingAs($instructor)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}", ['subtitle' => null])
        ->assertOk();

    expect($course->fresh()->subtitle)->toBeNull();
});

it('keeps the slug stable when the title changes', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->create();
    $originalSlug = $course->slug;

    $this->actingAs($instructor)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}", ['title' => 'A Completely New Title'])
        ->assertOk();

    // A published URL is shared, bookmarked and indexed; renaming must not break it.
    expect($course->fresh()->slug)->toBe($originalSlug);
});

it('denies editing someone else course', function (): void {
    $owner = User::factory()->instructor()->create();
    $stranger = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    expect($this->actingAs($stranger)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}", ['title' => 'Hijacked'])
        ->assertStatus(403))->toBeApiError('forbidden');
});

it('syncs tags and maintains their usage count', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->create();

    $this->actingAs($instructor)->patchJson("/api/v1/studio/courses/{$course->uuid}", [
        'tags' => ['Laravel', 'PHP', 'laravel'],
    ])->assertOk();

    // Duplicates collapse on the slug.
    expect($course->fresh()->tags)->toHaveCount(2);

    $this->actingAs($instructor)->patchJson("/api/v1/studio/courses/{$course->uuid}", [
        'tags' => ['PHP'],
    ])->assertOk();

    expect($course->fresh()->tags)->toHaveCount(1)
        ->and(CourseTag::where('slug', 'laravel')->value('usage_count'))->toBe(0)
        ->and(CourseTag::where('slug', 'php')->value('usage_count'))->toBe(1);
});

it('stores structured course details', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->create();

    $this->actingAs($instructor)->patchJson("/api/v1/studio/courses/{$course->uuid}", [
        'detail' => [
            'objectives' => ['Read Bengali verse', 'Analyse metre'],
            'requirements' => ['Basic Bengali'],
        ],
    ])->assertOk();

    expect($course->fresh()->detail->objectives)->toBe(['Read Bengali verse', 'Analyse metre'])
        ->and($course->fresh()->detail->requirements)->toBe(['Basic Bengali']);
});

it('rejects a category that does not exist', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->create();

    $this->actingAs($instructor)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}", ['category_id' => 999999])
        ->assertStatus(422);
});

it('accepts a real category', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->create();
    $category = CourseCategory::factory()->create();

    $this->actingAs($instructor)
        ->patchJson("/api/v1/studio/courses/{$course->uuid}", ['category_id' => $category->id])
        ->assertOk();

    expect($course->fresh()->category_id)->toBe($category->id);
});

it('soft deletes rather than destroying a course', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->create();

    $this->actingAs($instructor)->deleteJson("/api/v1/studio/courses/{$course->uuid}")
        ->assertNoContent();

    // Enrollments, orders and certificates will hang off this row.
    expect(Course::withTrashed()->find($course->id))->not->toBeNull()
        ->and(Course::find($course->id))->toBeNull();
});

it('lists only courses the caller may edit', function (): void {
    $mine = User::factory()->instructor()->create();
    $theirs = User::factory()->instructor()->create();
    Course::factory()->ownedBy($mine)->count(2)->create();
    Course::factory()->ownedBy($theirs)->count(3)->create();

    $response = $this->actingAs($mine)->getJson('/api/v1/studio/courses')->assertOk();

    expect($response->json('meta.total'))->toBe(2);
});

it('shows an admin every course', function (): void {
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    Course::factory()->count(4)->create();

    expect($this->actingAs($admin)->getJson('/api/v1/studio/courses')->assertOk()->json('meta.total'))
        ->toBe(4);
});
