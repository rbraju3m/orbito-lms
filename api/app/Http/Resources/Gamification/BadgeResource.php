<?php

declare(strict_types=1);

namespace App\Http\Resources\Gamification;

use App\Domain\Gamification\Models\Badge;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A badge in the catalogue — held or not.
 *
 * The criteria are RENDERED, not hidden: a badge whose requirement is a secret
 * is a badge nobody works towards, and every shape here is already public in
 * the sense that anybody can read the number and count.
 *
 * @mixin Badge
 */
final class BadgeResource extends BaseResource
{
    public function __construct($resource, private readonly bool $held = false, private readonly ?string $awardedAt = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'tier' => $this->tier->value,
            'tier_label' => $this->tier->label(),
            'icon_url' => $this->icon?->publicUrl(),
            'criteria' => [
                'type' => $this->criteria['type'] ?? null,
                'threshold' => isset($this->criteria['threshold']) ? (int) $this->criteria['threshold'] : null,
            ],
            'is_held' => $this->held,
            'awarded_at' => $this->awardedAt,
        ];
    }
}
