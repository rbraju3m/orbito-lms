<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Actions;

use App\Domain\Engagement\Enums\DiscussionStatus;
use App\Domain\Engagement\Exceptions\DiscussionRejected;
use App\Domain\Engagement\Models\Discussion;
use App\Domain\Engagement\Models\DiscussionReply;

/**
 * Marks one reply as the answer, resolving the thread.
 *
 * Passing null un-accepts, which matters more than it looks: somebody who
 * marks the wrong reply must be able to take it back, and a thread that can
 * only ever be resolved once would be wrong forever.
 */
final class AcceptDiscussionAnswer
{
    public function handle(Discussion $discussion, ?DiscussionReply $reply): Discussion
    {
        if (! $discussion->type->isAnswerable()) {
            // A "resolved" badge on a thread nobody asked anything in.
            throw DiscussionRejected::notAnswerable();
        }

        if ($reply !== null && $reply->discussion_id !== $discussion->id) {
            // Otherwise any reply id in the academy could be marked as the
            // answer to any question.
            throw DiscussionRejected::replyNotInThread();
        }

        $discussion->forceFill([
            'accepted_reply_id' => $reply?->id,
            /*
             * Un-accepting returns the thread to `answered` when it has
             * replies, not to `open` — the replies did not disappear. The
             * recounter agrees, because it derives the same two states.
             */
            'status' => $reply !== null
                ? DiscussionStatus::Resolved
                : ($discussion->reply_count > 0
                    ? DiscussionStatus::Answered
                    : DiscussionStatus::Open),
        ])->save();

        return $discussion->refresh();
    }
}
