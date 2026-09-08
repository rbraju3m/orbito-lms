<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Models;

use App\Domain\Gamification\Enums\TriggerEvent;
use Database\Factories\Gamification\GamificationRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One reason points are awarded.
 *
 * @property int $id
 * @property string $key
 * @property TriggerEvent $event_name
 * @property string $name
 * @property int $points
 * @property array<string, mixed>|null $conditions
 * @property bool $is_active
 * @property int $cooldown_seconds
 * @property int|null $max_per_day
 */
final class GamificationRule extends Model
{
    /** @use HasFactory<GamificationRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'key', 'event_name', 'name', 'points', 'conditions',
        'is_active', 'cooldown_seconds', 'max_per_day',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_name' => TriggerEvent::class,
            'conditions' => 'array',
            'is_active' => 'boolean',
            'points' => 'integer',
            'cooldown_seconds' => 'integer',
            'max_per_day' => 'integer',
        ];
    }

    /** @param  Builder<GamificationRule>  $query */
    public function scopeWatching(Builder $query, TriggerEvent $trigger): void
    {
        $query->where('event_name', $trigger)->where('is_active', true);
    }
}
