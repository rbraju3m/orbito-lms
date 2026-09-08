<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Events;

use App\Domain\Engagement\Models\Review;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A review became visible.
 *
 * Named in EVENTS.md §4 as a Phase 12 event and added when it got a consumer,
 * which is the rule that file states: an event with no listener is a thing to
 * keep in step for nothing.
 *
 * Distinct from `ReviewChanged`, which fires on every write — including
 * deletions and edits that leave a review pending — and carries only a course
 * id because it exists to trigger a recount. This one fires on the TRANSITION
 * to published, exactly once per review, and carries the review because there
 * is one.
 */
final class ReviewPublished
{
    use Dispatchable;

    public function __construct(public readonly Review $review) {}
}
