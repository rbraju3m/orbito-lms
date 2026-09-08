<?php

declare(strict_types=1);

namespace Database\Factories\Gamification;

use App\Domain\Gamification\Enums\TriggerEvent;
use App\Domain\Gamification\Models\GamificationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GamificationRule>
 */
final class GamificationRuleFactory extends Factory
{
    protected $model = GamificationRule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'rule.'.fake()->unique()->slug(2),
            'event_name' => TriggerEvent::ItemCompleted,
            'name' => 'Finish a lesson',
            'points' => 10,
            'conditions' => [],
            'is_active' => true,
            'cooldown_seconds' => 0,
            'max_per_day' => null,
        ];
    }

    public function watching(TriggerEvent $trigger): static
    {
        return $this->state(fn () => ['event_name' => $trigger]);
    }

    public function worth(int $points): static
    {
        return $this->state(fn () => ['points' => $points]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
