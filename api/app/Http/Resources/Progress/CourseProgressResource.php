<?php

declare(strict_types=1);

namespace App\Http\Resources\Progress;

use App\Domain\Progress\Models\CourseProgress;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin CourseProgress
 */
final class CourseProgressResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'completed_items' => $this->completed_items,
            'total_items' => $this->total_items,
            'percent' => (float) $this->percent,
            'is_complete' => $this->completed_at !== null,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'last_item_id' => $this->whenLoaded('lastItem', fn () => $this->lastItem?->uuid),
        ];
    }
}
