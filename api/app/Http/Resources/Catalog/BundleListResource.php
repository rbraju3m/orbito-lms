<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Models\Bundle;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * The thin shape for catalogue and studio lists.
 *
 * Carries a course COUNT rather than the courses: a page of twenty bundles
 * would otherwise carry a hundred course rows, and nothing on a card renders
 * them. Same rule as `CourseListResource` (docs/ARCHITECTURE §7).
 *
 * @mixin Bundle
 */
final class BundleListResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = strtoupper((string) config('orbito.currency.base'));

        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'subtitle' => $this->subtitle,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'published_at' => $this->published_at?->toIso8601String(),

            'course_count' => $this->whenCounted('courses'),
            'thumbnail_url' => $this->whenLoaded('thumbnail', fn () => $this->thumbnail?->publicUrl()),

            // Display only; the charge is re-read at checkout (ADR-05).
            'price' => $this->when(
                $this->resource->relationLoaded('product'),
                fn () => PriceView::for($this->resource->product, $currency),
            ),
        ];
    }
}
