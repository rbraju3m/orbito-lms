<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Support\PublishChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedRegistry();
    $this->checklist = app(PublishChecklist::class);
});

it('passes a complete course', function (): void {
    $course = Course::factory()->withCategory()->create();

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
    $course = Course::factory()->withCategory()->create([
        'subtitle' => null,
        'thumbnail_media_id' => null,
    ]);

    $advisory = collect($this->checklist->evaluate($course))
        ->where('blocking', false)
        ->where('passed', false)
        ->pluck('code');

    expect($advisory)->toContain('thumbnail_present', 'subtitle_present')
        ->and($this->checklist->isPublishable($course))->toBeTrue();
});

it('returns every check with its blocking flag and outcome', function (): void {
    $course = Course::factory()->withCategory()->create();

    foreach ($this->checklist->evaluate($course) as $check) {
        expect($check)->toHaveKeys(['code', 'field', 'message', 'blocking', 'passed']);
    }
});
