<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\RefundMethod;
use App\Domain\Commerce\Enums\RefundStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Money given back on an order — all of it or part of it.
 *
 * Written PENDING before anything leaves (the same order as a payment row is
 * written before the handoff), so a process that dies mid-call leaves a row an
 * operator can find rather than money that moved with no record.
 *
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property int|null $payment_id
 * @property int $amount_minor
 * @property string $currency
 * @property RefundMethod $method
 * @property RefundStatus $status
 * @property string|null $reason
 * @property bool $revokes_access
 * @property string|null $external_id
 * @property int|null $requested_by
 * @property string|null $failure_reason
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $failed_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class Refund extends Model
{
    protected $fillable = [
        'order_id', 'payment_id', 'amount_minor', 'currency', 'method', 'status', 'reason',
        'revokes_access', 'external_id', 'requested_by', 'failure_reason', 'completed_at', 'failed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => RefundMethod::class,
            'status' => RefundStatus::class,
            'revokes_access' => 'boolean',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $refund): void {
            $refund->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * The refund split across the order's lines — what each course, bundle
     * and download gave back. Revenue reports read these.
     *
     * @return HasMany<RefundLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RefundLine::class);
    }
}
