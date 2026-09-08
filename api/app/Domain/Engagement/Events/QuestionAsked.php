<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Events;

use App\Domain\Engagement\Models\Discussion;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody opened a thread on a course.
 *
 * Named in EVENTS.md §4 as a Phase 12 event and added when the notifications
 * slice gave it a listener: without it the bell is learner-only, and an
 * instructor has no reason to open it.
 *
 * Carries the Discussion because the row is still there — unlike
 * DiscussionReplied, which also fires for deletions and so speaks in ids.
 */
final class QuestionAsked
{
    use Dispatchable;

    public function __construct(public readonly Discussion $discussion) {}
}
