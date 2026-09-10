<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Models;

use App\Domain\Webhook\Enums\WebhookTopic;
use Carbon\CarbonInterface;
use Database\Factories\Webhook\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Somewhere an academy wants to hear about what happens in it.
 *
 * `secret` signs every delivery. It is encrypted at rest, `$hidden` here AND
 * absent from every resource — two independent misses to leak it (§ Patterns
 * established in Phase 15). The API shows it once, when it is minted.
 *
 * @property int $id
 * @property string $uuid
 * @property string $url
 * @property string|null $description
 * @property string $secret
 * @property list<string> $events
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property CarbonInterface|null $disabled_at
 * @property string|null $disabled_reason
 * @property CarbonInterface|null $last_delivered_at
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    protected $fillable = [
        'url', 'description', 'secret', 'events', 'is_active', 'consecutive_failures',
        'disabled_at', 'disabled_reason', 'last_delivered_at', 'created_by',
    ];

    protected $hidden = ['secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'disabled_at' => 'datetime',
            'last_delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $endpoint): void {
            $endpoint->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public function subscribesTo(WebhookTopic $topic): bool
    {
        return in_array($topic->value, $this->events, true);
    }
}
