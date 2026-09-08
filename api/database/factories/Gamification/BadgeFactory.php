<?php

declare(strict_types=1);

namespace Database\Factories\Gamification;

use App\Domain\Gamification\Enums\BadgeTier;
use App\Domain\Gamification\Models\Badge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Badge>
 */
final class BadgeFactory extends Factory
{
    protected $model = Badge::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'badge.'.fake()->unique()->slug(2),
            'name' => 'A badge',
            'description' => 'Earned by doing something.',
            'tier' => BadgeTier::Bronze,
            'criteria' => ['type' => 'points_total', 'threshold' => 100],
            'is_active' => true,
        ];
    }

    /** @param  array<string, mixed>  $criteria */
    public function requiring(array $criteria): static
    {
        return $this->state(fn () => ['criteria' => $criteria]);
    }
}
