<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\Gateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every webhook that arrived, verified or not.
 *
 * The unique (gateway, external_event_id) is what makes a replayed delivery a
 * no-op rather than a second enrolment. Rejected deliveries are stored too,
 * with `signature_verified` false: an unexplained burst of them is the first
 * sign somebody is probing the endpoint.
 *
 * @property array<string, mixed> $payload
 */
final class PaymentEvent extends Model
{
    protected $fillable = [
        'payment_id', 'gateway', 'external_event_id', 'type',
        'payload', 'signature_verified', 'received_at', 'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gateway' => Gateway::class,
            'payload' => 'array',
            'signature_verified' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
