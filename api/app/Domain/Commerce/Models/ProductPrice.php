<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Integer minor units + ISO currency, never a float (ADR-04).
 *
 * @property int $amount_minor
 * @property int|null $sale_amount_minor
 * @property string $currency
 * @property CarbonInterface|null $sale_starts_at
 * @property CarbonInterface|null $sale_ends_at
 */
final class ProductPrice extends Model
{
    protected $fillable = [
        'product_id', 'currency', 'amount_minor',
        'sale_amount_minor', 'sale_starts_at', 'sale_ends_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'sale_amount_minor' => 'integer',
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Whether the sale price applies RIGHT NOW.
     *
     * Evaluated live rather than stored, deliberately: a sale that ends at
     * midnight must end at midnight, not when a job next runs. The same
     * reasoning as enrollment expiry in Phase 9.
     */
    public function isOnSale(): bool
    {
        if ($this->sale_amount_minor === null) {
            return false;
        }

        $started = $this->sale_starts_at === null || ! $this->sale_starts_at->isFuture();
        $ended = $this->sale_ends_at !== null && $this->sale_ends_at->isPast();

        return $started && ! $ended;
    }

    /** What the buyer actually pays. The ONLY method checkout may ask. */
    public function effectiveMinor(): int
    {
        return $this->isOnSale() ? (int) $this->sale_amount_minor : $this->amount_minor;
    }
}
