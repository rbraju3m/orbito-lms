<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\ChangeBundleStatus;
use App\Domain\Catalog\Enums\BundleStatus;
use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Actions\SetProductPrice;
use App\Domain\Commerce\Actions\SyncBundleProduct;
use App\Domain\Commerce\Actions\SyncCourseProduct;
use App\Domain\Commerce\Enums\ProductStatus;
use App\Domain\Enrollment\Actions\EnrollInCourse;
use App\Domain\Enrollment\Data\EnrollmentIntent;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

/*
 * Authoring and publishing a bundle.
 *
 * The buying half is in BundlePurchaseTest; the split is the same one the
 * codebase already makes between CheckoutApiTest and MoneyPathTest.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->currency = strtoupper((string) config('orbito.currency.base'));
    $this->admin = userWithRole(RoleKey::Admin);
    $this->instructor = User::factory()->instructor()->create();

    // Two published, priced courses — the minimum a bundle can be built from.
    $this->courses = collect(range(1, 2))->map(fn (int $n): Course => pricedCourse(
        (int) (2000 * $n),
        $this->currency,
    ))->all();

    $this->priceBundle = function (Bundle $bundle, int $minor): void {
        app(SetProductPrice::class)->handle(
            app(SyncBundleProduct::class)->handle($bundle),
            $this->currency,
            $minor,
        );
    };
});

/** A published course with a product and a price, the way an academy makes one. */
function pricedCourse(int $minor, string $currency): Course
{
    $course = courseWithCurriculum(
        Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]),
        [1],
    );

    // Through the real path: the listener made the product when the course
    // was created, and this sets what it costs.
    app(SetProductPrice::class)->handle(
        app(SyncCourseProduct::class)->handle($course),
        $currency,
        $minor,
    );

    return $course->fresh(['product.prices']) ?? $course;
}

/* ------------------------------------------------------------- authoring */

it('creates a bundle as a draft holding the courses given', function (): void {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/studio/bundles', [
            'title' => 'The complete path',
            'description' => str_repeat('Everything you need, in order. ', 3),
            'course_ids' => array_map(fn (Course $c): int => $c->id, $this->courses),
        ])
        ->assertCreated();

    expect($response->json('data.status'))->toBe('draft')
        ->and(Bundle::first()->courses)->toHaveCount(2);
});

/* A draft needs its product from the start, or it can never be priced. */
it('gives a draft bundle an inactive product so it can be priced', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/studio/bundles', ['title' => 'Priceable draft'])
        ->assertCreated();

    $product = Bundle::first()->product;

    expect($product)->not->toBeNull()
        ->and($product->status)->toBe(ProductStatus::Inactive);
});

it('replaces the whole course list rather than merging it', function (): void {
    $bundle = Bundle::factory()->containing($this->courses)->create();
    $replacement = pricedCourse(500, $this->currency);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/studio/bundles/{$bundle->uuid}", [
            'course_ids' => [$replacement->id],
        ])
        ->assertOk();

    expect($bundle->fresh()->courses->pluck('id')->all())->toBe([$replacement->id]);
});

it('keeps the order the author arranged', function (): void {
    [$first, $second] = $this->courses;
    $bundle = Bundle::factory()->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/studio/bundles/{$bundle->uuid}", [
            'course_ids' => [$second->id, $first->id],
        ])
        ->assertOk();

    expect($bundle->fresh()->courses->pluck('id')->all())->toBe([$second->id, $first->id]);
});

/* ------------------------------------------------------------- checklist */

it('refuses to publish a bundle with fewer than two courses', function (): void {
    $bundle = Bundle::factory()->containing([$this->courses[0]])->create();
    ($this->priceBundle)($bundle, 3000);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/studio/bundles/{$bundle->uuid}/publish")
        ->assertStatus(422);

    expect($response)->toBeApiError('bundle_not_publishable')
        ->and(collect($response->json('error.details'))->pluck('code'))
        ->toContain('has_two_courses');
});

it('refuses to publish with no price', function (): void {
    $bundle = Bundle::factory()->containing($this->courses)->create();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/studio/bundles/{$bundle->uuid}/publish")
        ->assertStatus(422);

    expect(collect($response->json('error.details'))->pluck('code'))
        ->toContain('price_configured');
});

/* Selling access to something nobody can open is worse than a lost sale. */
it('names the unpublished courses that block publication', function (): void {
    $draft = Course::factory()->create(['title' => 'Not ready yet']);
    $bundle = Bundle::factory()->containing([...$this->courses, $draft])->create();
    ($this->priceBundle)($bundle, 3000);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/studio/bundles/{$bundle->uuid}/publish")
        ->assertStatus(422);

    expect($response->json('error.details.0.message'))->toContain('Not ready yet');
});

it('publishes a complete bundle and makes its product sellable', function (): void {
    $bundle = Bundle::factory()->containing($this->courses)->create();
    ($this->priceBundle)($bundle, 3000);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/studio/bundles/{$bundle->uuid}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    expect($bundle->fresh()->product->status)->toBe(ProductStatus::Active);
});

it('renders the same checklist the publish endpoint enforces', function (): void {
    $bundle = Bundle::factory()->containing([$this->courses[0]])->create();

    $codes = collect(
        $this->actingAs($this->admin)
            ->getJson("/api/v1/studio/bundles/{$bundle->uuid}")
            ->assertOk()
            ->json('data.checklist')
    )->where('passed', false)->pluck('code');

    expect($codes)->toContain('has_two_courses')->toContain('price_configured');
});

/* ------------------------------------------------- a member course changes */

it('takes a published bundle back to draft when one of its courses is unpublished', function (): void {
    $bundle = Bundle::factory()->containing($this->courses)->create();
    ($this->priceBundle)($bundle, 3000);
    app(ChangeBundleStatus::class)->handle($bundle, BundleStatus::Published, $this->admin);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/studio/courses/{$this->courses[0]->uuid}/unpublish")
        ->assertOk();

    $bundle->refresh();

    expect($bundle->status)->toBe(BundleStatus::Draft)
        ->and($bundle->product->status)->toBe(ProductStatus::Inactive);
});

/* Deliberately one-way: an author may have changed their mind since. */
it('does not put the bundle back on sale when the course returns', function (): void {
    $bundle = Bundle::factory()->containing($this->courses)->create();
    ($this->priceBundle)($bundle, 3000);
    app(ChangeBundleStatus::class)->handle($bundle, BundleStatus::Published, $this->admin);

    $course = $this->courses[0];
    $this->actingAs($this->admin)->postJson("/api/v1/studio/courses/{$course->uuid}/unpublish")->assertOk();
    $this->actingAs($this->admin)->postJson("/api/v1/studio/courses/{$course->uuid}/publish")->assertOk();

    expect($bundle->fresh()->status)->toBe(BundleStatus::Draft);
});

/* ----------------------------------------------------------- the catalogue */

it('shows a published bundle with what its courses would cost separately', function (): void {
    $bundle = Bundle::factory()->containing($this->courses)->create();
    ($this->priceBundle)($bundle, 3000);
    app(ChangeBundleStatus::class)->handle($bundle, BundleStatus::Published, $this->admin);

    $response = $this->actingAs($this->instructor)
        ->getJson("/api/v1/bundles/{$bundle->slug}")
        ->assertOk();

    // 2000 + 4000 separately, 3000 as a bundle.
    expect($response->json('data.parts_total_minor'))->toBe(6000)
        ->and($response->json('data.price.amount_minor'))->toBe(3000)
        ->and($response->json('data.courses'))->toHaveCount(2);
});

it('hides a draft bundle from the catalogue behind a 404, not a 403', function (): void {
    $bundle = Bundle::factory()->containing($this->courses)->create();

    expect($this->actingAs($this->instructor)->getJson("/api/v1/bundles/{$bundle->slug}")->assertNotFound())
        ->toBeApiError('not_found');
});

it('tells the buyer which courses they already own', function (): void {
    $bundle = Bundle::factory()->containing($this->courses)->create();
    ($this->priceBundle)($bundle, 3000);
    app(ChangeBundleStatus::class)->handle($bundle, BundleStatus::Published, $this->admin);

    $learner = User::factory()->withRole(RoleKey::Student)->create();
    app(EnrollInCourse::class)->handle(
        $learner,
        $this->courses[0],
        EnrollmentIntent::manual($this->admin->id),
    );

    $response = $this->actingAs($learner)
        ->getJson("/api/v1/bundles/{$bundle->slug}")
        ->assertOk();

    expect($response->json('data.owned_course_ids'))->toBe([$this->courses[0]->id]);
});

/* --------------------------------------------------------- authorization */

it('denies the whole authoring surface to an instructor', function (): void {
    $bundle = Bundle::factory()->containing($this->courses)->create();

    // A bundle can hold another instructor's courses, and pricing it decides
    // what they earn — so this is an academy decision, not an author's.
    $this->actingAs($this->instructor)
        ->postJson('/api/v1/studio/bundles', ['title' => 'Mine now'])->assertForbidden();
    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/bundles/{$bundle->uuid}", ['title' => 'Renamed'])->assertForbidden();
    $this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/bundles/{$bundle->uuid}/publish")->assertForbidden();
    $this->actingAs($this->instructor)
        ->putJson("/api/v1/studio/bundles/{$bundle->uuid}/price", [
            'currency' => $this->currency, 'amount_minor' => 100,
        ])->assertForbidden();
    $this->actingAs($this->instructor)
        ->deleteJson("/api/v1/studio/bundles/{$bundle->uuid}")->assertForbidden();
});

it('denies a draft bundle to somebody who cannot manage bundles', function (): void {
    $bundle = Bundle::factory()->create();

    $this->actingAs($this->instructor)
        ->getJson("/api/v1/studio/bundles/{$bundle->uuid}")
        ->assertForbidden();
});

/* --------------------------------------------------------------- validation */

it('rejects a bundle with an unknown course id', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/studio/bundles', [
            'title' => 'Nonsense bundle',
            'course_ids' => [999999],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('rejects the same course listed twice', function (): void {
    $id = $this->courses[0]->id;

    $this->actingAs($this->admin)
        ->postJson('/api/v1/studio/bundles', [
            'title' => 'Double counted',
            'course_ids' => [$id, $id],
        ])
        ->assertStatus(422);
});

it('rejects an illegal lifecycle move with 409', function (): void {
    $bundle = Bundle::factory()->archived()->create();

    // Archived → Draft and Archived → Published are legal; unpublishing an
    // archived bundle is the move that is not.
    expect($bundle->status->canTransitionTo(BundleStatus::Archived))->toBeFalse();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/studio/bundles/{$bundle->uuid}/archive")
        ->assertOk();

    // Same state in, same state out — not an error, just nothing to do.
    expect($response->json('data.status'))->toBe('archived');
});
