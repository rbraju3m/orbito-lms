<?php

declare(strict_types=1);

namespace App\Http\Resources\Engagement;

use App\Domain\Engagement\Models\Review;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One review.
 *
 * The author is named, which is the point of a review — an anonymous one
 * carries no weight. What is NOT exposed is their email or id: a course page
 * is a public-ish surface within the academy, and a reviewer should not become
 * contactable by being honest.
 *
 * `status` is present for everyone, deliberately. A learner whose review is
 * sitting in moderation must be able to see that it has not appeared;
 * discovering it silently vanished is worse than being told it is pending.
 *
 * @mixin Review
 */
final class ReviewResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->uuid,
            'rating' => $this->rating,
            'title' => $this->title,
            'body' => $this->body,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_published' => $this->status->isPubliclyVisible(),

            'author' => [
                'name' => $this->whenLoaded('user', fn () => $this->user->name ?? 'Former member'),
                // So the UI can offer "edit" without guessing at identity.
                'is_you' => $viewer !== null && $this->user_id === $viewer->id,
            ],

            'instructor_reply' => $this->instructor_reply,
            'replied_at' => $this->replied_at?->toIso8601String(),

            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
