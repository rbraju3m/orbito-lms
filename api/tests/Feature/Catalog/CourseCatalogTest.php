<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseCategory;
use App\Domain\Identity\Models\User;

use function Pest\Laravel\getJson;

beforeEach(fn () => seedRegistry());

it('lists only published, public courses to anonymous visitors', function (): void {
    Course::factory()->published()->count(3)->create();
    Course::factory()->count(2)->create();                    // drafts
    Course::factory()->published()->unlisted()->create();     // link-only
    Course::factory()->published()->private()->create();
    Course::factory()->archived()->create();

    $response = getJson('/api/v1/courses')->assertOk();

    expect($response->json('meta.total'))->toBe(3);
});

it('returns the documented pagination envelope', function (): void {
    Course::factory()->published()->count(3)->create();

    getJson('/api/v1/courses')->assertOk()->assertJsonStructure([
        'data' => [['id', 'slug', 'title', 'status', 'rating_avg', 'enrollment_count']],
        'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        'links' => ['first', 'prev', 'next', 'last'],
    ]);
});

it('does not include heavy fields in the list payload', function (): void {
    Course::factory()->published()->create();

    $first = getJson('/api/v1/courses')->assertOk()->json('data.0');

    // A list of 20 courses must not carry 20 long descriptions.
    expect($first)->not->toHaveKey('description')
        ->and($first)->not->toHaveKey('detail')
        ->and($first)->not->toHaveKey('publish_checklist');
});

it('filters by category', function (): void {
    $category = CourseCategory::factory()->create();
    Course::factory()->published()->count(2)->create(['category_id' => $category->id]);
    Course::factory()->published()->count(3)->create();

    expect(getJson("/api/v1/courses?category={$category->slug}")->assertOk()->json('meta.total'))
        ->toBe(2);
});

it('filters by level and language', function (): void {
    Course::factory()->published()->count(2)->create(['level' => 'beginner']);
    Course::factory()->published()->create(['level' => 'advanced', 'locale' => 'bn']);

    expect(getJson('/api/v1/courses?level=beginner')->assertOk()->json('meta.total'))->toBe(2);
    expect(getJson('/api/v1/courses?language=bn')->assertOk()->json('meta.total'))->toBe(1);
});

it('caps the requested page size', function (): void {
    Course::factory()->published()->count(3)->create();

    expect(getJson('/api/v1/courses?per_page=9999')->assertOk()->json('meta.per_page'))
        ->toBeLessThanOrEqual((int) config('orbito.pagination.max_per_page'));
});

it('serves a published course page by slug', function (): void {
    $course = Course::factory()->published()->create();

    getJson("/api/v1/courses/{$course->slug}")
        ->assertOk()
        ->assertJsonPath('data.slug', $course->slug)
        ->assertJsonStructure(['data' => ['id', 'title', 'description', 'instructors', 'detail']]);
});

it('serves an unlisted course by direct link', function (): void {
    $course = Course::factory()->published()->unlisted()->create();

    getJson("/api/v1/courses/{$course->slug}")->assertOk();
});

/*
 * 404, never 403: the status code must not confirm that an unpublished course
 * by that slug exists.
 */
it('hides an unpublished course behind a 404 for anonymous visitors', function (): void {
    $course = Course::factory()->create();

    expect(getJson("/api/v1/courses/{$course->slug}")->assertNotFound())->toBeApiError('not_found');
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

it('does not expose the publish checklist or settings to a visitor', function (): void {
    $course = Course::factory()->published()->create();

    $data = getJson("/api/v1/courses/{$course->slug}")->assertOk()->json('data');

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

    $response = getJson('/api/v1/categories')->assertOk();

    expect($response->json('data.0.children'))->toHaveCount(2)
        ->and($response->json('data.0.course_count'))->toBe(1);
});

it('renders a course list in a bounded number of queries', function (): void {
    Course::factory()->published()->count(10)->create();

    DB::enableQueryLog();
    getJson('/api/v1/courses')->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Ten courses must not mean ten category lookups (docs/ARCHITECTURE §7).
    expect($queries)->toBeLessThanOrEqual(6);
});
