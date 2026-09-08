<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Engagement;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Actions\ModerateReview;
use App\Domain\Engagement\Actions\ReplyToReview;
use App\Domain\Engagement\Actions\SubmitReview;
use App\Domain\Engagement\Enums\ReviewStatus;
use App\Domain\Engagement\Events\ReviewChanged;
use App\Domain\Engagement\Models\Review;
use App\Http\Requests\Engagement\ModerateReviewRequest;
use App\Http\Requests\Engagement\ReplyToReviewRequest;
use App\Http\Requests\Engagement\StoreReviewRequest;
use App\Http\Resources\Engagement\ReviewResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ReviewController
{
    /**
     * A course's reviews.
     *
     * Published ones for everybody, PLUS the caller's own whatever its status
     * — a learner must be able to see that their review is still waiting on a
     * moderator rather than concluding it vanished. Moderators additionally
     * see everything.
     *
     * Filtered by what the reader may see rather than by a query parameter: a
     * row that should not be there is a leak, not a preference (Phase 8).
     */
    public function index(Request $request, Course $course): JsonResponse
    {
        $viewer = $request->user();
        $moderator = $viewer->hasPermission('review.moderate');

        $reviews = Review::query()
            ->with('user')
            ->where('course_id', $course->id)
            ->when(! $moderator, fn ($query) => $query->where(
                fn ($q) => $q
                    ->where('status', ReviewStatus::Published)
                    ->orWhere('user_id', $viewer->id),
            ))
            // published_at has second precision, so the id is the tiebreak
            // that stops a row appearing on two pages or on none.
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(ReviewResource::collection($reviews));
    }

    /**
     * Write or replace the caller's review.
     *
     * A PUT in spirit — there is one review per learner per course, so this
     * is idempotent and there is deliberately no separate update route to
     * keep in step with it.
     */
    public function store(StoreReviewRequest $request, Course $course, SubmitReview $action): JsonResponse
    {
        $review = $action->handle(
            $request->user(),
            $course,
            $request->integer('rating'),
            $request->input('title'),
            $request->input('body'),
        );

        return ApiResponse::created(ReviewResource::make($review->load('user')));
    }

    public function destroy(Request $request, Review $review): JsonResponse
    {
        Gate::authorize('delete', $review);

        $courseId = $review->course_id;
        $review->delete();

        // The rating moves, so the same event every other path fires.
        ReviewChanged::dispatch($courseId);

        return ApiResponse::noContent();
    }

    public function moderate(
        ModerateReviewRequest $request,
        Review $review,
        ModerateReview $action,
    ): JsonResponse {
        Gate::authorize('moderate', $review);

        return ApiResponse::ok(
            ReviewResource::make($action->handle($review, $request->status())->load('user')),
        );
    }

    public function reply(
        ReplyToReviewRequest $request,
        Review $review,
        ReplyToReview $action,
    ): JsonResponse {
        Gate::authorize('reply', $review);

        return ApiResponse::ok(
            ReviewResource::make($action->handle($review, $request->input('reply'))->load('user')),
        );
    }

    /** The cross-course moderation queue. */
    public function queue(Request $request): JsonResponse
    {
        Gate::authorize('moderate-reviews');

        $reviews = Review::query()
            ->with(['user', 'course'])
            ->where('status', ReviewStatus::Pending)
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(ReviewResource::collection($reviews));
    }

    private function perPage(Request $request): int
    {
        return min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );
    }
}
