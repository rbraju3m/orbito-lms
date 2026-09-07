<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\ProductStatus;
use Database\Factories\Commerce\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $title
 * @property ProductStatus $status
 */
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = ['purchasable_type', 'purchasable_id', 'title', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => ProductStatus::class];
    }

    protected static function booted(): void
    {
        self::creating(function (self $product): void {
            $product->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return MorphTo<Model, $this> */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<ProductPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /** @param  Builder<Product>  $query */
    public function scopeSellable(Builder $query): void
    {
        $query->where('status', ProductStatus::Active);
    }

    /**
     * The price in one currency, or null when this product is not sold in it.
     *
     * A missing price is not zero and not a fallback to another currency:
     * charging someone in the wrong currency, or for nothing, are both worse
     * than refusing the sale.
     */
    public function priceIn(string $currency): ?ProductPrice
    {
        $this->loadMissing('prices');

        return $this->prices->firstWhere('currency', strtoupper($currency));
    }
}
