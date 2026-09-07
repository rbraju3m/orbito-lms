<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseCategory;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

/*
 * The catalogue is MEMBERS-ONLY under multi-tenancy: tenancy is resolved from
 * the authenticated user, so an anonymous request belongs to no academy and
 * there is no catalogue to serve it.
 *
 * These tests are unchanged in what they assert — published vs unlisted vs
 * private vs draft — but the reader is now a signed-in member with no staff
 * rights rather than a passer-by. That is the distinction that still exists.
 */
beforeEach(function (): void {
    seedRegistry();
    $this->member = User::factory()->withRole(RoleKey::Student)->create();
    $this->actingAs($this->member);
});

it('lists only published, public courses to an ordinary member', function (): void {
    Course::factory()->published()->count(3)->create();
    Course::factory()->count(2)->create();                    // drafts
    Course::factory()->published()->unlisted()->create();     // link-only
    Course::factory()->published()->private()->create();
    Course::factory()->archived()->create();

    $response = $this->getJson('/api/v1/courses')->assertOk();

    expect($response->json('meta.total'))->toBe(3);
});

it('returns the documented pagination envelope', function (): void {
    Course::factory()->published()->count(3)->create();

    $this->getJson('/api/v1/courses')->assertOk()->assertJsonStructure([
        'data' => [['id', 'slug', 'title', 'status', 'rating_avg', 'enrollment_count']],
        'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        'links' => ['first', 'prev', 'next', 'last'],
    ]);
});

it('does not include heavy fields in the list payload', function (): void {
    Course::factory()->published()->create();

    $first = $this->getJson('/api/v1/courses')->assertOk()->json('data.0');

    // A list of 20 courses must not carry 20 long descriptions.
    expect($first)->not->toHaveKey('description')
        ->and($first)->not->toHaveKey('detail')
        ->and($first)->not->toHaveKey('publish_checklist');
});

it('filters by category', function (): void {
    $category = CourseCategory::factory()->create();
    Course::factory()->published()->count(2)->create(['category_id' => $category->id]);
    Course::factory()->published()->count(3)->create();

    expect($this->getJson("/api/v1/courses?category={$category->slug}")->assertOk()->json('meta.total'))
        ->toBe(2);
});

it('filters by level and language', function (): void {
    Course::factory()->published()->count(2)->create(['level' => 'beginner']);
    Course::factory()->published()->create(['level' => 'advanced', 'locale' => 'bn']);

    expect($this->getJson('/api/v1/courses?level=beginner')->assertOk()->json('meta.total'))->toBe(2);
    expect($this->getJson('/api/v1/courses?language=bn')->assertOk()->json('meta.total'))->toBe(1);
});

it('caps the requested page size', function (): void {
    Course::factory()->published()->count(3)->create();

    expect($this->getJson('/api/v1/courses?per_page=9999')->assertOk()->json('meta.per_page'))
        ->toBeLessThanOrEqual((int) config('orbito.pagination.max_per_page'));
});

it('serves a published course page by slug', function (): void {
    $course = Course::factory()->published()->create();

    $this->getJson("/api/v1/courses/{$course->slug}")
        ->assertOk()
        ->assertJsonPath('data.slug', $course->slug)
        ->assertJsonStructure(['data' => ['id', 'title', 'description', 'instructors', 'detail']]);
});

it('serves an unlisted course by direct link', function (): void {
    $course = Course::factory()->published()->unlisted()->create();

    $this->getJson("/api/v1/courses/{$course->slug}")->assertOk();
});

/*
 * 404, never 403: the status code must not confirm that an unpublished course
 * by that slug exists.
 */
it('hides an unpublished course behind a 404 for an ordinary member', function (): void {
    $course = Course::factory()->create();

    expect($this->getJson("/api/v1/courses/{$course->slug}")->assertNotFound())->toBeApiError('not_found');
});

it('hides an unpublished course from an unrelated instructor', function (): void {
    $course = Course::factory()->create();
    $stranger = User::factory()->instructor()->create();

    $this->actingAs($stranger)->getJson("/api/v1/courses/{$course->slug}")->assertNotFound();
});

it('lets the owner preview their own unpublished course page', function (): void {
    $owner = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->create();

    $this->actingAs($owner)->getJson("/api/v1/courses/{$course->slug}")->assertOk();
});

it('does not expose the publish checklist or settings to an ordinary member', function (): void {
    $course = Course::factory()->published()->create();

    $data = $this->getJson("/api/v1/courses/{$course->slug}")->assertOk()->json('data');

    expect($data)->not->toHaveKey('publish_checklist')
        ->and($data)->not->toHaveKey('settings');
});

it('exposes the publish checklist to course staff', function (): void {
    $owner = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($owner)->published()->create();

    $data = $this->actingAs($owner)->getJson("/api/v1/courses/{$course->slug}")->assertOk()->json('data');

    expect($data['publish_checklist'])->toBeArray()->not->toBeEmpty()
        ->and($data)->toHaveKey('settings');
});

it('lists the category tree with course counts', function (): void {
    $parent = CourseCategory::factory()->create();
    CourseCategory::factory()->count(2)->create(['parent_id' => $parent->id]);
    Course::factory()->published()->create(['category_id' => $parent->id]);

    $response = $this->getJson('/api/v1/categories')->assertOk();

    expect($response->json('data.0.children'))->toHaveCount(2)
        ->and($response->json('data.0.course_count'))->toBe(1);
});

it('renders a course list in a bounded number of queries', function (): void {
    Course::factory()->published()->count(10)->create();

    DB::enableQueryLog();
    $this->getJson('/api/v1/courses')->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Ten courses must not mean ten category lookups (docs/ARCHITECTURE §7).
    expect($queries)->toBeLessThanOrEqual(6);
});
