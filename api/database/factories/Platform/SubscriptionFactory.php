<?php

declare(strict_types=1);

namespace Database\Factories\Platform;

use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Models\Subscription;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
            'current_period_starts_at' => now()->subDays(5),
            'current_period_ends_at' => now()->addMonth(),
            'canceled_at' => null,
            'grace_days' => 7,
        ];
    }

    /** Cover ran out and the grace window is spent. */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Expired,
            'current_period_ends_at' => now()->subMonth(),
        ]);
    }

    /** Inside the grace window — still writable, deliberately. */
    public function pastDue(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::PastDue,
            'current_period_ends_at' => now()->subDay(),
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => now()->subDay(),
        ]);
    }

    public function trialing(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays(10),
        ]);
    }
}
