<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Commerce\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $number
 * @property int $user_id
 * @property OrderStatus $status
 * @property string $currency
 * @property int|null $coupon_id
 * @property string|null $coupon_code the code as typed, frozen at checkout
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $total_minor
 * @property int $refunded_minor sum of COMPLETED refunds, kept by CompleteRefund
 * @property CarbonInterface|null $placed_at
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $cancelled_at
 */
final class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'number', 'user_id', 'status', 'currency', 'coupon_id', 'coupon_code',
        'subtotal_minor', 'discount_minor', 'total_minor', 'refunded_minor',
        'placed_at', 'paid_at', 'cancelled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'refunded_minor' => 'integer',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $order): void {
            $order->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * What can still be refunded: the total less every refund that holds part
     * of it — pending ones too, so two cannot race for the same money. From
     * the LOADED refunds, for display; `ClaimRefund` asks again under a lock.
     */
    public function refundableMinor(): int
    {
        $claimed = $this->refunds
            ->filter(static fn (Refund $refund): bool => $refund->status->claimsAmount())
            ->sum('amount_minor');

        return max(0, $this->total_minor - (int) $claimed);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A zero-total order needs no gateway at all.
     *
     * Free courses go through EnrollInCourse directly, so this only happens
     * when a discount takes a paid course to nothing — but sending £0.00 to a
     * gateway is an error at most providers, not a no-op.
     */
    public function isFree(): bool
    {
        return $this->total_minor === 0;
    }
}
