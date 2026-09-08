<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Models\Course;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * The thin shape for catalogue and Studio lists.
 *
 * Deliberately does NOT include description, details or curriculum: a list of
 * 20 courses must not carry 20 long descriptions (docs/ARCHITECTURE §7).
 *
 * @mixin Course
 */
final class CourseListResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            /*
             * The numeric id, beside the uuid. Endpoints that REFERENCE a
             * course rather than address it — prerequisites, and the bundle
             * builder in Phase 16 — speak in this, the same convention as
             * CourseItemResource and MediaResource.
             */
            'ref' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'level' => $this->level->value,
            'level_label' => $this->level->label(),
            'locale' => $this->locale,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'visibility' => $this->visibility->value,
            'pricing_model' => $this->pricing_model->value,

            /*
             * Display only. The figure that CHARGES is re-read at checkout;
             * null means "not buyable now", which is not the same as free.
             */
            'price' => CoursePrice::for(
                $this->resource,
                strtoupper((string) config('orbito.currency.base')),
            ),
            'thumbnail_url' => $this->whenLoaded('thumbnail', fn () => $this->thumbnail?->publicUrl()),

            // Denormalised columns, not computed on read.
            'item_count' => $this->item_count,
            'total_duration_seconds' => $this->total_duration_seconds,
            'enrollment_count' => $this->enrollment_count,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,

            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'category' => $this->whenLoaded(
                'category',
                fn () => $this->category ? [
                    'slug' => $this->category->slug,
                    'name' => $this->category->name,
                ] : null,
            ),

            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->uuid,
                'name' => $this->owner->name,
                'headline' => $this->owner->headline,
            ]),
        ];
    }
}
