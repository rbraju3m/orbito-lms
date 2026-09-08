<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Actions;

use App\Domain\Engagement\Events\DiscussionReplied;
use App\Domain\Engagement\Exceptions\DiscussionRejected;
use App\Domain\Engagement\Models\Discussion;
use App\Domain\Engagement\Models\DiscussionReply;
use App\Domain\Identity\Models\User;
use App\Support\Html\RichTextSanitizer;

/**
 * Adds a reply, at most one level deep.
 *
 * A reply to a reply is RE-PARENTED to the top-level one rather than refused.
 * Refusing would be a confusing error for something the UI allowed; arbitrary
 * depth is a rendering problem with no good answer and a recursive query on a
 * read path. Flattening is the only option that is neither.
 */
final class ReplyToDiscussion
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    public function handle(
        User $user,
        Discussion $discussion,
        string $body,
        ?DiscussionReply $parent = null,
        bool $isInstructor = false,
    ): DiscussionReply {
        if (! $discussion->status->isVisible()) {
            throw DiscussionRejected::threadClosed();
        }

        if ($parent !== null && $parent->discussion_id !== $discussion->id) {
            throw DiscussionRejected::replyNotInThread();
        }

        $reply = DiscussionReply::create([
            'discussion_id' => $discussion->id,
            'parent_id' => $this->topLevelParent($parent),
            'user_id' => $user->id,
            'body' => (string) $this->sanitizer->clean($body),
            /*
             * Frozen at write time. Somebody who answered as an instructor and
             * later lost the role still answered as one, and the badge should
             * not change under a thread people have already read.
             */
            'is_instructor_reply' => $isInstructor,
            'status' => DiscussionReply::STATUS_PUBLISHED,
        ]);

        DiscussionReplied::dispatch($discussion->id, $reply->id);

        return $reply;
    }

    /**
     * Flattens to one level.
     *
     * Replying to a top-level reply nests under it; replying to a NESTED reply
     * nests under that reply's parent, not under the reply. So a thread is at
     * most question → reply → reply, never deeper.
     */
    private function topLevelParent(?DiscussionReply $parent): ?int
    {
        if ($parent === null) {
            return null;
        }

        return $parent->parent_id ?? $parent->id;
    }
}
