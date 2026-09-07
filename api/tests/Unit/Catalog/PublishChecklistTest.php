<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Support\PublishChecklist;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;

beforeEach(function (): void {
    seedRegistry();
    $this->checklist = app(PublishChecklist::class);
});

it('passes a complete course', function (): void {
    $course = Course::factory()->publishable()->create();

    expect($this->checklist->isPublishable($course))->toBeTrue()
        ->and($this->checklist->blockingFailures($course))->toBe([]);
});

it('blocks a course with no category', function (): void {
    $course = Course::factory()->create(['category_id' => null]);

    $codes = collect($this->checklist->blockingFailures($course))->pluck('code');

    expect($this->checklist->isPublishable($course))->toBeFalse()
        ->and($codes)->toContain('category_present');
});

it('blocks a course whose description is too thin', function (): void {
    $course = Course::factory()->withCategory()->create(['description' => 'Short.']);

    expect(collect($this->checklist->blockingFailures($course))->pluck('code'))
        ->toContain('description_present');
});

it('does not count HTML markup towards the description length', function (): void {
    $course = Course::factory()->withCategory()->create([
        'description' => '<p><strong>'.str_repeat('<em></em>', 40).'Hi</strong></p>',
    ]);

    // A wall of empty tags is not a description.
    expect(collect($this->checklist->blockingFailures($course))->pluck('code'))
        ->toContain('description_present');
});

/* Pricing lands in Phase 10; until then a paid course cannot be published. */
it('blocks a paid course because pricing does not exist yet', function (): void {
    $course = Course::factory()->withCategory()->create(['pricing_model' => PricingModel::OneTime]);

    expect(collect($this->checklist->blockingFailures($course))->pluck('code'))
        ->toContain('price_configured');
});

it('reports advisory checks without blocking', function (): void {
    $course = Course::factory()->publishable()->create([
        'subtitle' => null,
        'thumbnail_media_id' => null,
    ]);

    $advisory = collect($this->checklist->evaluate($course->fresh()))
        ->where('blocking', false)
        ->where('passed', false)
        ->pluck('code');

    expect($advisory)->toContain('thumbnail_present', 'subtitle_present')
        ->and($this->checklist->isPublishable($course->fresh()))->toBeTrue();
});

it('returns every check with its blocking flag and outcome', function (): void {
    $course = Course::factory()->withCategory()->create();

    foreach ($this->checklist->evaluate($course) as $check) {
        expect($check)->toHaveKeys(['code', 'field', 'message', 'blocking', 'passed']);
    }
});

/* --- Curriculum rules, added in Phase 5 --- */

it('blocks a course with no sections', function (): void {
    $course = Course::factory()->withCategory()->create();

    expect(collect($this->checklist->blockingFailures($course))->pluck('code'))
        ->toContain('has_section', 'has_published_item');
});

it('blocks a course whose sections are all empty', function (): void {
    $course = Course::factory()->withCategory()->create();
    CourseSection::factory()->count(2)->create([
        'course_id' => $course->id,
    ]);

    $failures = collect($this->checklist->blockingFailures($course->fresh()));

    expect($failures->pluck('code'))->toContain('no_empty_sections')
        ->and($failures->firstWhere('code', 'no_empty_sections')['message'])
        // Name the sections; "some sections are empty" makes the author hunt.
        ->toContain('"');
});

it('blocks a course whose only items are unpublished', function (): void {
    $course = Course::factory()->withCategory()->create();
    $section = CourseSection::factory()->create(['course_id' => $course->id]);
    CourseItem::factory()->inSection($section)->unpublished()->create();

    expect(collect($this->checklist->blockingFailures($course->fresh()))->pluck('code'))
        ->toContain('has_published_item');
});

it('does not count a downloadable resource as teachable content', function (): void {
    $course = Course::factory()->withCategory()->create();
    $section = CourseSection::factory()->create(['course_id' => $course->id]);
    CourseItem::factory()->inSection($section)->resource()->create();

    // A learner does not "finish" a PDF, so a course of only resources has
    // nothing to complete.
    expect(collect($this->checklist->blockingFailures($course->fresh()))->pluck('code'))
        ->toContain('has_published_item');
});

it('passes a course with a real curriculum', function (): void {
    $course = courseWithCurriculum(Course::factory()->withCategory()->create(), [2, 2]);

    expect($this->checklist->isPublishable($course->fresh()))->toBeTrue();
});

it('suggests a free preview without blocking', function (): void {
    $course = courseWithCurriculum(Course::factory()->withCategory()->create(), [2]);

    $advisory = collect($this->checklist->evaluate($course->fresh()))
        ->where('blocking', false)
        ->where('passed', false)
        ->pluck('code');

    expect($advisory)->toContain('has_preview_item')
        ->and($this->checklist->isPublishable($course->fresh()))->toBeTrue();
});
