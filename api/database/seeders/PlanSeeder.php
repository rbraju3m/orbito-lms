<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Platform\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The plans an academy can be on.
 *
 * `firstOrCreate` on the slug so re-running never duplicates and never
 * overwrites a price somebody has adjusted in production.
 */
final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'starter', 'name' => 'Starter', 'position' => 0,
                'price_minor' => 0, 'trial_days' => 0, 'grace_days' => 7,
                'limits' => ['max_courses' => 3, 'max_students' => 50, 'max_instructors' => 1],
            ],
            [
                'slug' => 'growth', 'name' => 'Growth', 'position' => 1,
                'price_minor' => 4900, 'trial_days' => 14, 'grace_days' => 7,
                'limits' => ['max_courses' => 50, 'max_students' => 1000, 'max_instructors' => 10],
            ],
            [
                'slug' => 'scale', 'name' => 'Scale', 'position' => 2,
                'price_minor' => 19900, 'trial_days' => 14, 'grace_days' => 14,
                // An empty limits map means uncapped, not "no allowance".
                'limits' => [],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->firstOrCreate(
                ['slug' => $plan['slug']],
                $plan + ['currency' => 'USD', 'billing_period' => 'monthly', 'is_active' => true],
            );
        }
    }
}
