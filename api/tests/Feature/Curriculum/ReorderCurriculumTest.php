<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(function (): void {
    seedRegistry();
    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->create(),
        [2, 2],
    );
});

/** The tree in its current order, in the shape the endpoint accepts. */
function currentTree(Course $course): array
{
    return CourseSection::where('course_id', $course->id)
        ->orderBy('position')
        ->get()
        ->map(fn (CourseSection $section) => [
            'id' => $section->id,
            'item_ids' => $section->items()->orderBy('position')->pluck('id')->all(),
        ])
        ->all();
}

it('reorders items within a section', function (): void {
    $tree = currentTree($this->course);
    $tree[0]['item_ids'] = array_reverse($tree[0]['item_ids']);

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}/curriculum/order", ['sections' => $tree])
        ->assertOk();

    $after = currentTree($this->course->fresh());
    expect($after[0]['item_ids'])->toBe($tree[0]['item_ids']);
});

it('moves an item between sections', function (): void {
    $tree = currentTree($this->course);
    $moved = array_shift($tree[0]['item_ids']);
    $tree[1]['item_ids'][] = $moved;

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}/curriculum/order", ['sections' => $tree])
        ->assertOk();

    expect(CourseItem::find($moved)->section_id)->toBe($tree[1]['id'])
        ->and(currentTree($this->course->fresh())[0]['item_ids'])->toHaveCount(1);
});

it('reorders whole sections', function (): void {
    $tree = array_reverse(currentTree($this->course));

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}/curriculum/order", ['sections' => $tree])
        ->assertOk();

    $after = currentTree($this->course->fresh());
    expect($after[0]['id'])->toBe($tree[0]['id']);
});

/*
 * Positions stay dense and course-global after any move, which is what keeps
 * prev/next a single indexed query (ADR-01).
 */
it('keeps positions dense and course-global after a move', function (): void {
    $tree = currentTree($this->course);
    $moved = array_shift($tree[0]['item_ids']);
    array_unshift($tree[1]['item_ids'], $moved);

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}/curriculum/order", ['sections' => $tree])
        ->assertOk();

    $positions = CourseItem::where('course_id', $this->course->id)
        ->orderBy('position')
        ->pluck('position');

    expect($positions->all())->toBe(range(0, 3));
});

it('is idempotent', function (): void {
    $tree = currentTree($this->course);

    foreach (range(1, 3) as $_) {
        $this->actingAs($this->instructor)
            ->patchJson("/api/v1/studio/courses/{$this->course->uuid}/curriculum/order", ['sections' => $tree])
            ->assertOk();
    }

    expect(currentTree($this->course->fresh()))->toBe($tree);
});

/*
 * A payload that is not a permutation means the client is working from a stale
 * tree. Applying it would silently drop someone's work.
 */
it('rejects a reorder that drops an item', function (): void {
    $tree = currentTree($this->course);
    array_pop($tree[1]['item_ids']);

    expect($this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}/curriculum/order", ['sections' => $tree])
        ->assertStatus(409))->toBeApiError('curriculum_rejected');
});

it('rejects a reorder that duplicates an item', function (): void {
    $tree = currentTree($this->course);
    $tree[1]['item_ids'][] = $tree[0]['item_ids'][0];

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}/curriculum/order", ['sections' => $tree])
        ->assertStatus(409);
});

it('rejects a reorder that omits a section', function (): void {
    $tree = currentTree($this->course);
    array_pop($tree);

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}/curriculum/order", ['sections' => $tree])
        ->assertStatus(409);
});

it('rejects an item that belongs to another course', function (): void {
    $other = courseWithCurriculum(Course::factory()->create(), [1]);
    $foreignItemId = $other->items()->first()->id;

    $tree = currentTree($this->course);
    $tree[0]['item_ids'][] = $foreignItemId;

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$this->course->uuid}/curriculum/order", ['sections' => $tree])
        ->assertStatus(409);

    // The foreign item must not have been dragged into someone else's course.
    expect(CourseItem::find($foreignItemId)->course_id)->toBe($other->id);
});

it('denies reordering to an unrelated instructor', function (): void {
    $stranger = User::factory()->instructor()->create();

    $this->actingAs($stranger)
        ->patchJson(
            "/api/v1/studio/courses/{$this->course->uuid}/curriculum/order",
            ['sections' => currentTree($this->course)],
        )
        ->assertStatus(403);
});

it('lets a course manager reorder their assigned course only', function (): void {
    $manager = User::factory()->withRole(RoleKey::Student)->create();
    $manager->assignRole(RoleKey::CourseManager, $this->course);

    $this->actingAs($manager)
        ->patchJson(
            "/api/v1/studio/courses/{$this->course->uuid}/curriculum/order",
            ['sections' => currentTree($this->course)],
        )
        ->assertOk();

    $other = courseWithCurriculum(Course::factory()->create(), [1]);

    $this->actingAs($manager->fresh())
        ->patchJson(
            "/api/v1/studio/courses/{$other->uuid}/curriculum/order",
            ['sections' => currentTree($other)],
        )
        ->assertStatus(403);
});

it('returns the whole tree so the client can reconcile', function (): void {
    $response = $this->actingAs($this->instructor)
        ->patchJson(
            "/api/v1/studio/courses/{$this->course->uuid}/curriculum/order",
            ['sections' => currentTree($this->course)],
        )
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.items'))->toHaveCount(2);
});
