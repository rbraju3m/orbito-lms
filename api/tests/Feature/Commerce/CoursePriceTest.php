<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Models\Product;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

/*
 * The catalogue's price, which exists so a buy button can name a figure
 * without a second request. It is a LABEL: what charges is re-read at
 * checkout (ADR-05).
 */

beforeEach(function (): void {
    seedRegistry();
    $this->currency = strtoupper((string) config('orbito.currency.base'));
    $this->student = User::factory()->withRole(RoleKey::Student)->create();
});

it('carries the price and the product id the basket speaks', function (): void {
    $course = Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]);
    $product = Product::factory()->pricedAt(4900, $this->currency)->create([
        'purchasable_id' => $course->id,
    ]);

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$course->slug}")
        ->assertOk()
        ->assertJsonPath('data.price.amount_minor', 4900)
        ->assertJsonPath('data.price.currency', $this->currency)
        ->assertJsonPath('data.price.is_on_sale', false)
        // Without this the button would have to look the product up by course.
        ->assertJsonPath('data.price.product_id', $product->uuid);
});

it('reports a sale price with the original beside it', function (): void {
    $course = Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]);
    $product = Product::factory()->create(['purchasable_id' => $course->id]);
    $product->prices()->create([
        'currency' => $this->currency,
        'amount_minor' => 9900,
        'sale_amount_minor' => 4900,
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addDay(),
    ]);

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$course->slug}")
        ->assertOk()
        ->assertJsonPath('data.price.amount_minor', 4900)
        ->assertJsonPath('data.price.is_on_sale', true)
        // So "was 99" is a fact the UI renders rather than one it infers.
        ->assertJsonPath('data.price.list_amount_minor', 9900);
});

it('sends null for a free course, which is not the same as unbuyable', function (): void {
    $course = Course::factory()->published()->create(['pricing_model' => PricingModel::Free]);

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$course->slug}")
        ->assertOk()
        ->assertJsonPath('data.price', null)
        ->assertJsonPath('data.pricing_model', 'free');
});

it('sends null when the product has been deactivated', function (): void {
    // Still `one_time`, so the page says paid — but there is nothing to buy.
    // Two different reasons for an unbuyable button, and the UI needs both.
    $course = Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]);
    Product::factory()->inactive()->pricedAt(4900, $this->currency)
        ->create(['purchasable_id' => $course->id]);

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$course->slug}")
        ->assertOk()
        ->assertJsonPath('data.price', null)
        ->assertJsonPath('data.pricing_model', 'one_time');
});

it('sends null when the course is not sold in the platform currency', function (): void {
    $course = Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]);
    Product::factory()->pricedAt(4900, 'XYZ')->create(['purchasable_id' => $course->id]);

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$course->slug}")
        ->assertOk()
        ->assertJsonPath('data.price', null);
});

it('prices the catalogue listing without an N+1', function (): void {
    foreach (range(1, 5) as $i) {
        $course = Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]);
        Product::factory()->pricedAt(1000 * $i, $this->currency)
            ->create(['purchasable_id' => $course->id]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $response = $this->actingAs($this->student)->getJson('/api/v1/courses')->assertOk();

    expect(collect($response->json('data'))->pluck('price.amount_minor')->filter())
        ->toHaveCount(5)
        // Eager-loaded: a per-row price lookup would put this well past 30.
        ->and($queries)->toBeLessThan(30);
});
