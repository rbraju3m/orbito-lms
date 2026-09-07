<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Models\UsageCounter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * O(1) reads and atomic writes for the metrics plan limits are sold on.
 *
 * Counters drift if a job dies, so `usage:reconcile` recomputes from source and
 * reports the drift — the same discipline as course progress (ADR-02).
 */
final class UsageCounters
{
    public const PLATFORM = 'platform';

    /** Platform-wide rows use owner_id 0, never NULL — see the migration. */
    private const PLATFORM_ID = 0;

    /**
     * Rows not attributable to one academy use '' rather than NULL, for the
     * same reason: this column leads the unique index and MySQL treats NULLs
     * there as distinct, so a nullable value would let those rows duplicate.
     */
    private const NO_TENANT = '';

    public function increment(UsageMetric $metric, ?Model $owner = null, int $by = 1): void
    {
        $this->adjust($metric, $owner, $by);
    }

    public function decrement(UsageMetric $metric, ?Model $owner = null, int $by = 1): void
    {
        $this->adjust($metric, $owner, -$by);
    }

    public function get(UsageMetric $metric, ?Model $owner = null): int
    {
        [$type, $id] = $this->key($owner);

        return (int) UsageCounter::query()
            ->where('tenant_id', $this->tenant())
            ->where('owner_type', $type)
            ->where('owner_id', $id)
            ->where('metric', $metric->value)
            ->value('value');
    }

    /** @return array<string, int> */
    public function all(?Model $owner = null): array
    {
        [$type, $id] = $this->key($owner);

        /** @var array<string, int> */
        return UsageCounter::query()
            ->where('tenant_id', $this->tenant())
            ->where('owner_type', $type)
            ->where('owner_id', $id)
            ->pluck('value', 'metric')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    public function set(UsageMetric $metric, ?Model $owner, int $value): void
    {
        [$type, $id] = $this->key($owner);

        UsageCounter::query()->updateOrCreate(
            [
                'tenant_id' => $this->tenant(),
                'owner_type' => $type,
                'owner_id' => $id,
                'metric' => $metric->value,
            ],
            ['value' => max(0, $value), 'reconciled_at' => now()],
        );
    }

    /**
     * One atomic statement. Two concurrent uploads must not read-modify-write
     * their way into a lost update, and an upsert followed by a separate UPDATE
     * leaves exactly that window open.
     */
    private function adjust(UsageMetric $metric, ?Model $owner, int $delta): void
    {
        [$type, $id] = $this->key($owner);
        $now = now();

        // On insert the row starts at max(0, delta); on collision it moves by
        // delta, floored at zero. GREATEST keeps a double-fired decrement from
        // driving a count negative.
        DB::connection('mysql')->statement(
            <<<'SQL'
                INSERT INTO usage_counters (tenant_id, owner_type, owner_id, metric, value, created_at, updated_at)
                VALUES (?, ?, ?, ?, GREATEST(0, ?), ?, ?)
                ON DUPLICATE KEY UPDATE
                    value = GREATEST(0, value + ?),
                    updated_at = ?
                SQL,
            [$this->tenant(), $type, $id, $metric->value, $delta, $now, $now, $delta, $now],
        );
    }

    /** @return array{0: string, 1: int} */
    private function key(?Model $owner): array
    {
        return $owner === null
            ? [self::PLATFORM, self::PLATFORM_ID]
            : [$owner->getMorphClass(), (int) $owner->getKey()];
    }

    /**
     * Which academy this counter belongs to.
     *
     * The table is central, so without this every academy would increment the
     * SAME rows — one shared "courses published" figure for the whole platform,
     * and plan limits enforced against everyone else's usage. The raw statement
     * below also has to name the central connection explicitly, because the
     * default one is the tenant's whenever this runs inside a request.
     */
    private function tenant(): string
    {
        return (string) (tenancy()->tenant?->getTenantKey() ?? self::NO_TENANT);
    }
}
