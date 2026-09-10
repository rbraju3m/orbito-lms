<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Models\Bundle;
use App\Domain\Enrollment\Models\Enrollment;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One bundle, in full.
 *
 * The per-reader facts live HERE and not on the list (§16): `owned_course_ids`
 * is a query per bundle, and thirty cards would be thirty queries for
 * something no card renders.
 *
 * `parts_total_minor` is what the same courses would cost bought separately.
 * It is the whole argument for buying a bundle, so the server computes it from
 * the same prices `PlaceOrder` will read rather than leaving the client to add
 * up numbers it may not have all of.
 *
 * @mixin Bundle
 */
final class BundleResource extends BaseResource
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
            'description' => $this->description,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'published_at' => $this->published_at?->toIso8601String(),

            'thumbnail_url' => $this->whenLoaded('thumbnail', fn () => $this->thumbnail?->publicUrl()),

            'price' => $this->when(
                $this->resource->relationLoaded('product'),
                fn () => PriceView::for($this->resource->product, $currency),
            ),

            'courses' => $this->whenLoaded(
                'courses',
                fn () => CourseListResource::collection($this->courses)->resolve($request),
            ),

            /*
             * What the same courses cost separately, and what that saves.
             * Absent rather than zero when the courses are not loaded — "we
             * did not ask" and "they are worth nothing" are different facts.
             */
            'parts_total_minor' => $this->whenLoaded(
                'courses',
                fn (): int => $this->partsTotal($currency),
            ),

            /*
             * Which of these the reader already has. Partial overlap does NOT
             * block the sale (docs/BUNDLES.md §1), so the page owes them a
             * plain statement of what is new before they pay, rather than a
             * refusal or a surprise.
             */
            'owned_course_ids' => $this->when(
                $this->resource->relationLoaded('courses') && $request->user() !== null,
                fn (): array => $this->ownedCourseIds($request),
            ),
        ];
    }

    private function partsTotal(string $currency): int
    {
        $total = 0;

        foreach ($this->courses as $course) {
            $total += $course->relationLoaded('product')
                ? ($course->product?->priceIn($currency)?->effectiveMinor() ?? 0)
                : 0;
        }

        return $total;
    }

    /** @return list<int> */
    private function ownedCourseIds(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        /** @var list<int> */
        return Enrollment::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $this->courses->pluck('id'))
            ->distinct()
            ->pluck('course_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
