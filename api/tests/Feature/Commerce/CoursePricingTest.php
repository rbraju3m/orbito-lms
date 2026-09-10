<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Enums\ProductStatus;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

/*
 * Setting what a course costs.
 *
 * This path did not exist until Phase 16. `SyncCourseProduct` was written in
 * P10 and wired to nothing, there was no endpoint that could write a price,
 * and `PublishChecklist` passed `price_configured` only for FREE courses — so
 * a paid course could not be published and the whole paid path was
 * unreachable through the API. Every commerce test started from
 * `Product::factory()`, which is why the suite never saw it.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->currency = strtoupper((string) config('orbito.currency.base'));
    $this->instructor = User::factory()->instructor()->create();

    // Everything the publish checklist wants EXCEPT a price, so the price is
    // the only thing these tests are varying.
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->withCategory()
            ->create(['pricing_model' => PricingModel::OneTime]),
        [1],
    );
});

it('creates the product when a course becomes paid', function (): void {
    $free = Course::factory()->ownedBy($this->instructor)->create([
        'pricing_model' => PricingModel::Free,
    ]);

    expect($free->fresh('product')->product?->status)->not->toBe(ProductStatus::Active);

    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/courses/{$free->uuid}", ['pricing_model' => 'one_time'])
        ->assertOk();

    expect($free->fresh('product')->product)->not->toBeNull();
});

it('lets the owning instructor set a price', function (): void {
    $response = $this->actingAs($this->instructor)
        ->putJson("/api/v1/studio/courses/{$this->course->uuid}/price", [
            'currency' => $this->currency,
            'amount_minor' => 4900,
        ])
        ->assertOk();

    expect($response->json('data.amount_minor'))->toBe(4900)
        ->and($response->json('data.currency'))->toBe($this->currency);
});

/* The bug this phase fixed: a paid course used to be unpublishable, full stop. */
it('blocks publication of a paid course with no price, naming the reason', function (): void {
    $response = $this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/courses/{$this->course->uuid}/publish")
        ->assertStatus(422);

    expect(collect($response->json('error.details'))->pluck('code'))
        ->toContain('price_configured');
});

it('publishes a paid course once it has a price, and makes it sellable', function (): void {
    $this->actingAs($this->instructor)
        ->putJson("/api/v1/studio/courses/{$this->course->uuid}/price", [
            'currency' => $this->currency,
            'amount_minor' => 4900,
        ])->assertOk();

    $this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/courses/{$this->course->uuid}/publish")
        ->assertOk();

    $fresh = $this->course->fresh('product');

    expect($fresh->status)->toBe(CourseStatus::Published)
        ->and($fresh->product->status)->toBe(ProductStatus::Active);
});

/* A price in some other currency is a real price, but not one the academy reports in. */
it('does not accept a price in another currency as the publishable one', function (): void {
    $other = collect(config('orbito.currency.supported'))
        ->map(strtoupper(...))
        ->first(fn (string $c): bool => $c !== $this->currency);

    if ($other === null) {
        $this->markTestSkipped('Only one currency is configured.');
    }

    $this->actingAs($this->instructor)
        ->putJson("/api/v1/studio/courses/{$this->course->uuid}/price", [
            'currency' => $other,
            'amount_minor' => 4900,
        ])->assertOk();

    $this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/courses/{$this->course->uuid}/publish")
        ->assertStatus(422);
});

it('refuses to price a free course, and says which field to change', function (): void {
    $free = Course::factory()->ownedBy($this->instructor)->create([
        'pricing_model' => PricingModel::Free,
    ]);

    $response = $this->actingAs($this->instructor)
        ->putJson("/api/v1/studio/courses/{$free->uuid}/price", [
            'currency' => $this->currency,
            'amount_minor' => 4900,
        ])
        ->assertStatus(422);

    expect($response)->toBeApiError('pricing_rejected')
        ->and($response->json('error.details.0.field'))->toBe('pricing_model');
});

it('refuses a currency the academy does not sell in', function (): void {
    $this->actingAs($this->instructor)
        ->putJson("/api/v1/studio/courses/{$this->course->uuid}/price", [
            'currency' => 'XYZ',
            'amount_minor' => 4900,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('refuses a sale price that is not cheaper', function (): void {
    $this->actingAs($this->instructor)
        ->putJson("/api/v1/studio/courses/{$this->course->uuid}/price", [
            'currency' => $this->currency,
            'amount_minor' => 4900,
            'sale_amount_minor' => 4900,
        ])
        ->assertStatus(422);
});

it('refuses a price of zero rather than treating it as free', function (): void {
    $this->actingAs($this->instructor)
        ->putJson("/api/v1/studio/courses/{$this->course->uuid}/price", [
            'currency' => $this->currency,
            'amount_minor' => 0,
        ])
        ->assertStatus(422);
});

it('replaces the price rather than accumulating rows', function (): void {
    foreach ([4900, 3900] as $amount) {
        $this->actingAs($this->instructor)
            ->putJson("/api/v1/studio/courses/{$this->course->uuid}/price", [
                'currency' => $this->currency,
                'amount_minor' => $amount,
            ])->assertOk();
    }

    $product = $this->course->fresh('product.prices')->product;

    expect($product->prices)->toHaveCount(1)
        ->and($product->prices->first()->amount_minor)->toBe(3900);
});

/* Pricing is its own permission: editing the words is not setting the price. */
it('denies pricing to another instructor', function (): void {
    $other = User::factory()->instructor()->create();

    $this->actingAs($other)
        ->putJson("/api/v1/studio/courses/{$this->course->uuid}/price", [
            'currency' => $this->currency,
            'amount_minor' => 100,
        ])
        ->assertForbidden();
});

it('denies pricing to a student', function (): void {
    $this->actingAs(User::factory()->withRole(RoleKey::Student)->create())
        ->putJson("/api/v1/studio/courses/{$this->course->uuid}/price", [
            'currency' => $this->currency,
            'amount_minor' => 100,
        ])
        ->assertForbidden();
});
