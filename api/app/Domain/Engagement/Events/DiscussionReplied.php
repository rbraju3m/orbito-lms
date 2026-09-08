<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A thread's replies changed — added, hidden or deleted.
 *
 * Carries the discussion id rather than the reply, because it fires for
 * deletions too and every listener needs the same one thing: which thread to
 * recount. Phase 12's notifications listen to this as well.
 */
final class DiscussionReplied
{
    use Dispatchable;

    public function __construct(
        public readonly int $discussionId,
        /** Null for a deletion. The notifier needs it; the recounter does not. */
        public readonly ?int $replyId = null,
    ) {}
}
