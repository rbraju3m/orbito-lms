<?php

declare(strict_types=1);

namespace Database\Factories\Commerce;

use App\Domain\Commerce\Enums\DiscountType;
use App\Domain\Commerce\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An ACTIVE 20%-off coupon for anything, with no limits — every constraint is
 * a state a test opts into.
 *
 * @extends Factory<Coupon>
 */
final class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'code' => 'SAVE'.Str::upper(Str::random(6)),
            'description' => null,
            'discount_type' => DiscountType::Percent,
            'percent_off' => 20,
            'amount_off_minor' => null,
            'currency' => null,
            'applies_to_all' => true,
            'is_active' => true,
        ];
    }

    public function percent(int $percent): static
    {
        return $this->state(fn (): array => [
            'discount_type' => DiscountType::Percent,
            'percent_off' => $percent,
            'amount_off_minor' => null,
        ]);
    }

    public function fixed(int $minor, string $currency): static
    {
        return $this->state(fn (): array => [
            'discount_type' => DiscountType::Fixed,
            'percent_off' => null,
            'amount_off_minor' => $minor,
            'currency' => $currency,
        ]);
    }
}
