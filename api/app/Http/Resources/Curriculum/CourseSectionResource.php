<?php

declare(strict_types=1);

namespace App\Http\Resources\Curriculum;

use App\Domain\Curriculum\Models\CourseSection;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin CourseSection
 */
final class CourseSectionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'position' => $this->position,
            'items' => CourseItemResource::collection($this->whenLoaded('items')),
            'item_count' => $this->whenLoaded('items', fn () => $this->items->count()),
            'duration_seconds' => $this->whenLoaded(
                'items',
                fn () => (int) $this->items->sum('duration_seconds'),
            ),
        ];
    }
}
