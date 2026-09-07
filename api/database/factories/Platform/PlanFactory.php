<?php

declare(strict_types=1);

namespace Database\Factories\Platform;

use App\Domain\Platform\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => Str::title($name),
            'description' => null,
            'price_minor' => 4900,
            'currency' => 'USD',
            'billing_period' => 'monthly',
            'trial_days' => 14,
            'grace_days' => 7,
            'limits' => ['max_courses' => 50, 'max_students' => 500],
            'features' => [],
            'is_active' => true,
            'position' => 0,
        ];
    }

    /** No trial: the subscription starts active on day one. */
    public function withoutTrial(): static
    {
        return $this->state(fn () => ['trial_days' => 0]);
    }

    public function uncapped(): static
    {
        return $this->state(fn () => ['limits' => []]);
    }
}
