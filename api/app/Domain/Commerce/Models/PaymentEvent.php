<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\Gateway;
use Carbon\CarbonInterface;
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
 * A refund report the webhook could not settle is flagged `needs_attention`,
 * with why in `attention` (ReconcileProviderRefund), until a person resolves
 * it on the refund-reports screen.
 *
 * @property int $id
 * @property int|null $payment_id
 * @property Gateway $gateway
 * @property string $external_event_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property bool $signature_verified
 * @property CarbonInterface $received_at
 * @property CarbonInterface|null $processed_at
 * @property bool $needs_attention
 * @property list<array<string, mixed>>|null $attention
 * @property CarbonInterface|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolution_note
 */
final class PaymentEvent extends Model
{
    protected $fillable = [
        'payment_id', 'gateway', 'external_event_id', 'type',
        'payload', 'signature_verified', 'received_at', 'processed_at',
        'needs_attention', 'attention', 'resolved_at', 'resolved_by', 'resolution_note',
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
            'needs_attention' => 'boolean',
            'attention' => 'array',
            'resolved_at' => 'datetime',
            'resolved_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
