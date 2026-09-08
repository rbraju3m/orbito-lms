<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Actions;

use App\Domain\Engagement\Models\Review;
use App\Support\Html\RichTextSanitizer;

/**
 * The instructor's public answer to a review.
 *
 * A column on the review rather than a discussion thread: there is exactly one
 * reply, it belongs to the review, and modelling it as a conversation invites
 * an argument under a one-star rating that nobody moderates.
 *
 * Replying does NOT change the rating or the status. An instructor must not be
 * able to promote a review by answering it — that would make the average a
 * function of who bothered to respond.
 */
final class ReplyToReview
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    public function handle(Review $review, ?string $reply): Review
    {
        $clean = $reply === null || trim($reply) === ''
            ? null
            : $this->sanitizer->clean($reply);

        $review->forceFill([
            'instructor_reply' => $clean,
            // Cleared when the reply is removed, so "replied" and "has a
            // reply" cannot disagree.
            'replied_at' => $clean === null ? null : now(),
        ])->save();

        return $review->refresh();
    }
}
