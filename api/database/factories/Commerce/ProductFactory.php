<?php

declare(strict_types=1);

namespace Database\Factories\Commerce;

use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Enums\ProductStatus;
use App\Domain\Commerce\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'purchasable_type' => 'course',
            'purchasable_id' => Course::factory()->published(),
            'title' => fake()->sentence(3),
            'status' => ProductStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ProductStatus::Inactive]);
    }

    /** Sold in one currency at a fixed price. */
    public function pricedAt(int $minor, string $currency = 'USD'): static
    {
        return $this->afterCreating(function (Product $product) use ($minor, $currency): void {
            $product->prices()->create([
                'currency' => strtoupper($currency),
                'amount_minor' => $minor,
            ]);
        });
    }
}
