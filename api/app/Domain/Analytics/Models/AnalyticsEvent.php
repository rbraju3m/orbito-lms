<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Analytics\Enums\EventName;
use App\Domain\Analytics\Enums\EventSource;
use Carbon\CarbonInterface;
use Database\Factories\Analytics\AnalyticsEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the append-only log.
 *
 * There is no `update()` path and no policy, because nothing may edit history
 * and nothing but a rollup job reads it. `updated_at` is off for the same
 * reason: a row that never changes has nothing to record about changing.
 *
 * @property int $id
 * @property EventName $name
 * @property CarbonInterface $occurred_at
 * @property int|null $actor_id
 * @property string|null $session_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $course_id
 * @property int|null $course_item_id
 * @property array<string, mixed>|null $properties
 * @property string|null $ip_hash
 * @property EventSource $source
 */
final class AnalyticsEvent extends Model
{
    /** @use HasFactory<AnalyticsEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'name', 'occurred_at', 'actor_id', 'session_id', 'subject_type', 'subject_id',
        'course_id', 'course_item_id', 'properties', 'ip_hash', 'source',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'name' => EventName::class,
            'source' => EventSource::class,
            'occurred_at' => 'datetime',
            'properties' => 'array',
        ];
    }

    /**
     * Everything a rollup asks for is a half-open range: `[from, to)`.
     *
     * Half-open, not BETWEEN, because `occurred_at` has millisecond precision
     * — `BETWEEN '...00:00:00' AND '...23:59:59'` drops the last second of
     * every day, which nobody notices until a total is quietly short.
     *
     * @param  Builder<AnalyticsEvent>  $query
     */
    public function scopeOccurredBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to);
    }
}
