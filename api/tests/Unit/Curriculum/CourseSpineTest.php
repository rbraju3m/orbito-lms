<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRegistry());

/*
 * ADR-01: position is course-global, so prev/next is one indexed query rather
 * than a join across heterogeneous content tables.
 */
it('walks forward across section boundaries', function (): void {
    $course = courseWithCurriculum(Course::factory()->create(), [2, 2]);
    $items = $course->items()->orderBy('position')->get();

    expect($items)->toHaveCount(4)
        ->and($items[0]->next()?->id)->toBe($items[1]->id)
        // Crossing from the last item of section 1 into section 2.
        ->and($items[1]->next()?->id)->toBe($items[2]->id)
        ->and($items[3]->next())->toBeNull();
});

it('walks backward across section boundaries', function (): void {
    $course = courseWithCurriculum(Course::factory()->create(), [2, 2]);
    $items = $course->items()->orderBy('position')->get();

    expect($items[2]->previous()?->id)->toBe($items[1]->id)
        ->and($items[0]->previous())->toBeNull();
});

it('skips unpublished items when walking', function (): void {
    $course = courseWithCurriculum(Course::factory()->create(), [3]);
    $items = $course->items()->orderBy('position')->get();

    $items[1]->update(['is_published' => false]);

    expect($items[0]->next()?->id)->toBe($items[2]->id);
});

it('finds the next item in a single query', function (): void {
    $course = courseWithCurriculum(Course::factory()->create(), [3, 3, 3]);
    $first = $course->items()->orderBy('position')->first();

    DB::enableQueryLog();
    $first->next();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1);
});

it('gives every item in a course a distinct position', function (): void {
    $course = courseWithCurriculum(Course::factory()->create(), [3, 2, 4]);

    $positions = CourseItem::where('course_id', $course->id)->pluck('position');

    expect($positions)->toHaveCount(9)
        ->and($positions->unique())->toHaveCount(9)
        ->and($positions->sort()->values()->all())->toBe(range(0, 8));
});
