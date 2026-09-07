<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Models\CourseCategory;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin CourseCategory
 */
final class CourseCategoryResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'position' => $this->position,
            'is_active' => $this->is_active,
            'children' => self::collection($this->whenLoaded('children')),
            'course_count' => $this->whenCounted('courses'),
        ];
    }
}
