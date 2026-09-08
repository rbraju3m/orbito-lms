<?php

declare(strict_types=1);

namespace App\Http\Resources\Engagement;

use App\Domain\Engagement\Models\WishlistItem;
use App\Http\Resources\Catalog\CourseListResource;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A saved course.
 *
 * The COURSE is the payload — a wishlist row on its own is two ids and a
 * timestamp, and every screen that renders one wants the card. Reusing
 * CourseListResource means a saved course and a browsed course look identical,
 * price included.
 *
 * @mixin WishlistItem
 */
final class WishlistItemResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'saved_at' => $this->created_at?->toIso8601String(),
            'course' => $this->whenLoaded(
                'course',
                fn () => CourseListResource::make($this->course)->resolve($request),
            ),
        ];
    }
}
