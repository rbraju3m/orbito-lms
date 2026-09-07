<?php

declare(strict_types=1);

namespace App\Http\Resources\Progress;

use App\Domain\Curriculum\Models\CourseSection;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin CourseSection
 */
final class LearnerSectionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'position' => $this->position,
            'items' => LearnerItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
