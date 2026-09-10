<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $currency
 * @property int|null $coupon_id
 * @property Coupon|null $coupon
 */
final class Cart extends Model
{
    protected $fillable = ['user_id', 'currency', 'coupon_id', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $cart): void {
            $cart->uuid ??= (string) Str::uuid7();
        });
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /** Central users table — no FK crosses the schema boundary. */
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The coupon somebody applied. Only a POINTER: whether it still applies,
     * and for how much, is asked again on every read and at checkout.
     *
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * The lines that can be bought, priced NOW, keyed by cart item id — what
     * `CouponRules` is asked about. An unavailable line (no price in this
     * currency, or off sale) is left out: it blocks checkout on its own.
     *
     * @return array<int, array{product_id: int, amount_minor: int}>
     */
    public function pricedLines(): array
    {
        $this->loadMissing('items.product.prices');

        $lines = [];

        foreach ($this->items as $item) {
            $price = $item->product?->priceIn($this->currency);

            if ($item->product !== null && $price !== null && $item->product->status->isSellable()) {
                $lines[$item->id] = ['product_id' => $item->product->id, 'amount_minor' => $price->effectiveMinor()];
            }
        }

        return $lines;
    }
}
