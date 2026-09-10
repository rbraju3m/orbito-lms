<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\DiscountType;
use Carbon\CarbonInterface;
use Database\Factories\Commerce\CouponFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A code an academy hands out for money off. Whether it applies to a given
 * basket, for a given person, right now, is `CouponRules`' question.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string|null $description
 * @property DiscountType $discount_type
 * @property int|null $percent_off
 * @property int|null $amount_off_minor
 * @property string|null $currency
 * @property bool $applies_to_all
 * @property int|null $min_subtotal_minor
 * @property int|null $max_redemptions
 * @property int|null $max_redemptions_per_user
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property bool $is_active
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'description', 'discount_type', 'percent_off', 'amount_off_minor', 'currency',
        'applies_to_all', 'min_subtotal_minor', 'max_redemptions', 'max_redemptions_per_user',
        'starts_at', 'ends_at', 'is_active', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'percent_off' => 'integer',
            'amount_off_minor' => 'integer',
            'applies_to_all' => 'boolean',
            'min_subtotal_minor' => 'integer',
            'max_redemptions' => 'integer',
            'max_redemptions_per_user' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $coupon): void {
            $coupon->uuid ??= (string) Str::uuid7();
        });

        // One spelling in the table, so the unique index means what it says:
        // `save10` and `SAVE10 ` are the same code to a person typing it.
        self::saving(function (self $coupon): void {
            $coupon->code = self::normalise($coupon->code);
            $coupon->currency = $coupon->currency !== null ? strtoupper($coupon->currency) : null;
        });
    }

    public static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_products')->withTimestamps();
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
