<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use Database\Factories\Platform\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property int $price_minor
 * @property string $currency
 * @property int $trial_days
 * @property int $grace_days
 * @property array<string, int|null>|null $limits
 * @property array<string, mixed>|null $features
 * @property bool $is_active
 */
final class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /*
     * CENTRAL. See the pin note on User — an unpinned central model follows
     * the academy's connection once tenancy is initialised.
     */
    protected $connection = 'mysql';

    protected $fillable = [
        'slug', 'name', 'description', 'price_minor', 'currency', 'billing_period',
        'trial_days', 'grace_days', 'limits', 'features', 'is_active', 'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
            'price_minor' => 'integer',
            'trial_days' => 'integer',
            'grace_days' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The cap for one metric, or null for uncapped.
     *
     * Absent and null mean the same thing here — a plan that does not mention
     * `max_courses` does not cap courses — which is what lets a limit be added
     * to the product without rewriting every existing plan row.
     */
    public function limit(string $metric): ?int
    {
        $value = $this->limits[$metric] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
