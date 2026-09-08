<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Models;

use App\Domain\Gamification\Enums\BadgeTier;
use App\Domain\Media\Models\Media;
use Database\Factories\Gamification\BadgeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something worth having.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int|null $icon_media_id
 * @property BadgeTier $tier
 * @property array<string, mixed> $criteria
 * @property bool $is_active
 */
final class Badge extends Model
{
    /** @use HasFactory<BadgeFactory> */
    use HasFactory;

    protected $fillable = ['key', 'name', 'description', 'icon_media_id', 'tier', 'criteria', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tier' => BadgeTier::class,
            'criteria' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Media, $this> */
    public function icon(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'icon_media_id');
    }

    /** @param  Builder<Badge>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
