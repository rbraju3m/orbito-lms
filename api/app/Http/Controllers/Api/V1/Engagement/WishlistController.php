<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Engagement;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Models\WishlistItem;
use App\Http\Resources\Engagement\WishlistItemResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Courses somebody means to come back to.
 *
 * No policy: every row is keyed on the caller's own id and the queries are
 * scoped to it, so there is no other person's wishlist to authorize against.
 * The course must be one they may SEE, which the catalogue's own visibility
 * rules already decide.
 */
final class WishlistController
{
    public function index(Request $request): JsonResponse
    {
        $items = WishlistItem::query()
            // The card, price included — a saved course and a browsed one
            // should look identical.
            ->with(['course.category', 'course.owner', 'course.thumbnail', 'course.product.prices'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(WishlistItemResource::collection($items));
    }

    /**
     * Saving. Idempotent: saving twice is somebody clicking twice, not an
     * error worth a 409 — the unique key is what makes that true.
     */
    public function store(Request $request, Course $course): JsonResponse
    {
        $item = WishlistItem::firstOrCreate([
            'user_id' => $request->user()->id,
            'course_id' => $course->id,
        ]);

        return ApiResponse::created(WishlistItemResource::make(
            $item->load(['course.category', 'course.owner', 'course.thumbnail', 'course.product.prices']),
        ));
    }

    /** Removing. Also idempotent — 204 whether or not it was there. */
    public function destroy(Request $request, Course $course): JsonResponse
    {
        WishlistItem::query()
            ->where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->delete();

        return ApiResponse::noContent();
    }

    private function perPage(Request $request): int
    {
        return min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );
    }
}
