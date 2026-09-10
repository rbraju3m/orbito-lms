<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Models;

use App\Domain\Webhook\Enums\DeliveryStatus;
use Carbon\CarbonInterface;
use Database\Factories\Webhook\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One event on its way to one endpoint, and what happened when we tried.
 *
 * `body` is the exact bytes that are signed and sent, frozen when the event
 * fired — a retry or a redelivery sends the same message, never a re-read of
 * rows that may have changed since (§ Patterns established in Phase 12: a
 * notification is a frozen MESSAGE). `event_id` is shared by every delivery
 * of one event, including redeliveries, so a receiver can drop duplicates.
 *
 * @property int $id
 * @property string $uuid
 * @property int $endpoint_id
 * @property string $event_id
 * @property string $topic
 * @property string $body
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property CarbonInterface|null $next_attempt_at
 * @property CarbonInterface|null $last_attempt_at
 * @property CarbonInterface|null $delivered_at
 * @property int|null $response_status
 * @property string|null $response_body
 * @property string|null $error
 * @property int|null $duration_ms
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'endpoint_id', 'event_id', 'topic', 'body', 'status', 'attempts', 'next_attempt_at',
        'last_attempt_at', 'delivered_at', 'response_status', 'response_body', 'error', 'duration_ms',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $delivery): void {
            $delivery->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
