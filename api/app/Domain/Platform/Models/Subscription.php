<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Platform\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Database\Factories\Platform\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $tenant_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property CarbonInterface|null $trial_ends_at
 * @property CarbonInterface|null $current_period_ends_at
 * @property CarbonInterface|null $canceled_at
 * @property int $grace_days
 */
final class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'trial_ends_at',
        'current_period_starts_at', 'current_period_ends_at', 'canceled_at', 'grace_days',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'grace_days' => 'integer',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function permitsWrites(): bool
    {
        return $this->status->permitsWrites();
    }

    /**
     * When cover actually ran out — the trial's end while trialing, the
     * period's end otherwise.
     */
    public function coverEndsAt(): ?CarbonInterface
    {
        return $this->status === SubscriptionStatus::Trialing
            ? $this->trial_ends_at
            : $this->current_period_ends_at;
    }

    /**
     * Grace is counted from when cover ENDED, not from when the sweep ran.
     *
     * A sweep that misses three nights must not hand out three extra days —
     * which means a subscription lapsed longer ago than its grace window
     * crosses both cliffs in one pass and lands on `expired`, deliberately,
     * because that window is already spent.
     */
    public function graceEndsAt(): ?CarbonInterface
    {
        return $this->coverEndsAt()?->copy()->addDays($this->grace_days);
    }
}
