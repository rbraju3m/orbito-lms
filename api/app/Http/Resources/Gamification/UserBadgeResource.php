<?php

declare(strict_types=1);

namespace App\Http\Resources\Gamification;

use App\Domain\Gamification\Models\UserBadge;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin UserBadge
 */
final class UserBadgeResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->badge->key,
            'name' => $this->badge->name,
            'description' => $this->badge->description,
            'tier' => $this->badge->tier->value,
            'tier_label' => $this->badge->tier->label(),
            'icon_url' => $this->badge->icon?->publicUrl(),
            'awarded_at' => $this->awarded_at->toIso8601String(),
        ];
    }
}
