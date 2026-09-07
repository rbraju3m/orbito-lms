<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(function (): void {
    seedRegistry();
    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [2, 2]);
    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    $this->enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);
});

it('bootstraps the player with curriculum, access and progress', function (): void {
    $response = $this->actingAs($this->student)
        ->getJson("/api/v1/learn/courses/{$this->course->uuid}")
        ->assertOk();

    expect($response->json('data.access.granted'))->toBeTrue()
        ->and($response->json('data.curriculum'))->toHaveCount(2)
        ->and($response->json('data.curriculum.0.items'))->toHaveCount(2)
        ->and($response->json('data.progress.total_items'))->toBe(4)
        ->and((float) $response->json('data.progress.percent'))->toBe(0.0);
});

it('tells an unenrolled visitor why they cannot get in', function (): void {
    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    $response = $this->actingAs($stranger)
        ->getJson("/api/v1/learn/courses/{$this->course->uuid}")
        ->assertOk();

    // Any member of the academy sees the outline; the content is not.
    expect($response->json('data.access.granted'))->toBeFalse()
        ->and($response->json('data.access.reason'))->toBe('not_enrolled')
        ->and($response->json('data.curriculum'))->toHaveCount(2);
});

it('hides an unpublished course from someone who cannot see it', function (): void {
    $draft = courseWithCurriculum(Course::factory()->create(), [1]);
    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    // 404, not 403: not confirming the draft exists is the whole point.
    $this->actingAs($stranger)
        ->getJson("/api/v1/learn/courses/{$draft->uuid}")->assertNotFound();
});

it('omits unpublished items from the learner curriculum', function (): void {
    $this->course->items()->first()->update(['is_published' => false]);

    $response = $this->actingAs($this->student)
        ->getJson("/api/v1/learn/courses/{$this->course->uuid}")
        ->assertOk();

    $items = collect($response->json('data.curriculum'))->flatMap(fn ($s) => $s['items']);
    expect($items)->toHaveCount(3);
});

it('serves item content to an enrolled learner with prev and next', function (): void {
    $items = $this->course->items()->orderBy('position')->get();

    $response = $this->actingAs($this->student)
        ->getJson("/api/v1/learn/items/{$items[1]->uuid}")
        ->assertOk();

    expect($response->json('data.previous_id'))->toBe($items[0]->uuid)
        // Prev/next crosses the section boundary — position is course-global.
        ->and($response->json('data.next_id'))->toBe($items[2]->uuid);
});

it('returns 423 rather than 403 when content is locked', function (): void {
    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    // Locked, not forbidden: the caller may legitimately gain access by
    // enrolling, and the UI shows a "get access" screen for this.
    $response = $this->actingAs($stranger)
        ->getJson("/api/v1/learn/items/{$this->course->items()->first()->uuid}")
        ->assertStatus(423);

    expect($response)->toBeApiError('content_locked');
    expect($response->json('error.details.0.code'))->toBe('not_enrolled');
});

/*
 * Preview used to mean "try before you SIGN UP". Under multi-tenancy an
 * anonymous request belongs to no academy, so it now means "try before you
 * ENROL": a member of the academy who has not enrolled still gets the item.
 */
it('serves a preview item to a member who is not enrolled', function (): void {
    $item = $this->course->items()->first();
    $item->update(['is_preview' => true]);

    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($stranger)->getJson("/api/v1/learn/items/{$item->uuid}")->assertOk();
});

it('turns an anonymous caller away entirely', function (): void {
    $item = $this->course->items()->first();
    $item->update(['is_preview' => true]);

    $this->getJson("/api/v1/learn/items/{$item->uuid}")->assertStatus(401);
});

it('refuses an unpublished item outright', function (): void {
    $item = $this->course->items()->first();
    $item->update(['is_published' => false]);

    $this->actingAs($this->student)->getJson("/api/v1/learn/items/{$item->uuid}")->assertNotFound();
});

it('records the item as seen when it is opened', function (): void {
    $item = $this->course->items()->first();

    $this->actingAs($this->student)->getJson("/api/v1/learn/items/{$item->uuid}")->assertOk();

    expect($this->enrollment->fresh('progress')->progress->last_item_id)->toBe($item->id);
});

it('lets course staff preview the player without an enrollment', function (): void {
    $owner = User::factory()->instructor()->create();
    $course = courseWithCurriculum(
        Course::factory()->ownedBy($owner)->published()->create(),
        [1],
    );

    $response = $this->actingAs($owner)
        ->getJson("/api/v1/learn/courses/{$course->uuid}")
        ->assertOk();

    expect($response->json('data.access.is_staff'))->toBeTrue()
        ->and($response->json('data.progress'))->toBeNull();
});

it('bootstraps the player in a bounded number of queries', function (): void {
    $course = courseWithCurriculum(Course::factory()->published()->create(), [5, 5, 5]);
    $student = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $student->id]);

    DB::enableQueryLog();
    $this->actingAs($student)->getJson("/api/v1/learn/courses/{$course->uuid}")->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 15 items must not mean 15 progress lookups.
    expect($queries)->toBeLessThanOrEqual(12);
});

/*
 * The default guard is `sanctum`, so a bearer token authenticates on every
 * route rather than only the ones that name a guard. This mattered more when
 * the player was open to anonymous callers — a token was ignored there and an
 * enrolled learner arrived looking anonymous — and it still matters now,
 * because the tenant middleware reads the user the token resolves to.
 */
it('recognises a bearer token on routes that also allow anonymous access', function (): void {
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $this->student->email,
        'password' => 'password',
        'device_name' => 'Phone',
    ])->assertOk()->json('data.token');

    $response = $this->getJson(
        "/api/v1/learn/courses/{$this->course->uuid}",
        ['Authorization' => "Bearer {$token}"],
    )->assertOk();

    expect($response->json('data.access.granted'))->toBeTrue()
        ->and($response->json('data.access.reason'))->not->toBe('unauthenticated');
});

/*
 * The bootstrap is behind auth now, so `unauthenticated` is no longer a reason
 * the player can ever render — the request does not reach it. A member who has
 * not enrolled gets the outline with `not_enrolled`, which is the state the
 * "get access" screen actually exists for.
 */
it('gives a signed-in non-member the outline with a not_enrolled reason', function (): void {
    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    $response = $this->actingAs($stranger)
        ->getJson("/api/v1/learn/courses/{$this->course->uuid}")->assertOk();

    expect($response->json('data.access.granted'))->toBeFalse()
        ->and($response->json('data.access.reason'))->toBe('not_enrolled');
});

it('refuses the bootstrap to an anonymous caller', function (): void {
    $this->getJson("/api/v1/learn/courses/{$this->course->uuid}")->assertStatus(401);
});
