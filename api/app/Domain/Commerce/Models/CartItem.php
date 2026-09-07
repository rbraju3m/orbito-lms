<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately carries NO price.
 *
 * A figure captured when the item entered the cart is exactly the stale price
 * ADR-05 refuses to trust; PlaceOrder re-reads it at the moment of ordering.
 */
final class CartItem extends Model
{
    protected $fillable = ['cart_id', 'product_id'];

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
