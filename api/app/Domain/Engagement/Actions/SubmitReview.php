<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Actions;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Enums\ReviewStatus;
use App\Domain\Engagement\Events\ReviewChanged;
use App\Domain\Engagement\Exceptions\ReviewRejected;
use App\Domain\Engagement\Models\Review;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use App\Support\Html\RichTextSanitizer;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Writes or replaces one learner's review of one course.
 *
 * REPLACES rather than appends. The unique key on (course_id, user_id) is the
 * guarantee: without it a learner could push a course's average around by
 * reviewing it repeatedly, and the average is the entire reason this domain
 * exists.
 *
 * Whether a review appears immediately or waits for a human is the academy's
 * decision, read from the course settings — not this action's to hardcode.
 */
final class SubmitReview
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    public function handle(
        User $user,
        Course $course,
        int $rating,
        ?string $title = null,
        ?string $body = null,
    ): Review {
        $course->loadMissing('setting');

        if (! $course->setting?->enable_reviews) {
            throw ReviewRejected::notEnabled();
        }

        /*
         * Only somebody who took the course. Checked against the enrolment
         * table rather than CourseAccess: a learner whose access has since
         * expired has still genuinely taken the course and may still say so,
         * which `CourseAccess` — asking "may they consume this?" — would deny.
         */
        $enrollment = Enrollment::query()
            ->where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->first();

        if ($enrollment === null) {
            throw ReviewRejected::notEnrolled();
        }

        $moderated = (bool) ($course->setting->moderate_reviews ?? false);

        $attributes = [
            'enrollment_id' => $enrollment->id,
            'rating' => $rating,
            'title' => $title,
            // Sanitised on WRITE, never on render, so the stored value is safe
            // for the API, mobile and exports alike (CLAUDE.md §12).
            'body' => $body === null ? null : $this->sanitizer->clean($body),
            'status' => $moderated ? ReviewStatus::Pending : ReviewStatus::Published,
            'published_at' => $moderated ? null : now(),
        ];

        try {
            $review = Review::updateOrCreate(
                ['course_id' => $course->id, 'user_id' => $user->id],
                $attributes,
            );
        } catch (UniqueConstraintViolationException) {
            // Two submissions raced. The constraint refused the second; take
            // the row that won and apply this edit to it.
            $review = Review::where('course_id', $course->id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $review->forceFill($attributes)->save();
        }

        /*
         * Fired even when the review lands as `pending` and changes nothing.
         * An edit that DEMOTES a published review to pending — which happens
         * when an academy turns moderation on — must recount, and the listener
         * deciding whether anything moved is cheaper than this action guessing.
         */
        ReviewChanged::dispatch($course->id);

        return $review->refresh();
    }
}
