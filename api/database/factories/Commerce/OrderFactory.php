<?php

declare(strict_types=1);

namespace Database\Factories\Commerce;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'number' => now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'user_id' => User::factory()->withRole(RoleKey::Student),
            'status' => OrderStatus::Pending,
            'currency' => 'USD',
            'subtotal_minor' => 4900,
            'discount_minor' => 0,
            'total_minor' => 4900,
            'placed_at' => now(),
            'paid_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function awaitingPayment(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::AwaitingPayment]);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Paid, 'paid_at' => now()]);
    }
}
