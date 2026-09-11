<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property Gateway $gateway
 * @property string|null $external_id
 * @property string|null $provider_payment_id
 * @property PaymentStatus $status
 * @property int $amount_minor
 * @property string $currency
 * @property CarbonInterface|null $initiated_at
 * @property CarbonInterface|null $captured_at
 * @property CarbonInterface|null $failed_at
 * @property string|null $failure_reason
 * @property int $order_id
 */
final class Payment extends Model
{
    protected $fillable = [
        'order_id', 'gateway', 'external_id', 'provider_payment_id', 'status',
        'currency', 'amount_minor',
        'initiated_at', 'captured_at', 'failed_at', 'failure_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gateway' => Gateway::class,
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'initiated_at' => 'datetime',
            'captured_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $payment): void {
            $payment->uuid ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
