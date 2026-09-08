<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Actions;

use App\Domain\Engagement\Enums\DiscussionStatus;
use App\Domain\Engagement\Models\Discussion;
use App\Domain\Engagement\Models\DiscussionReply;

/**
 * Writes `reply_count`, `last_reply_at` and the derived status.
 *
 * Same shape as RecalculateCourseRating and for the same reason: a course's
 * Q&A list renders dozens of threads, and "how many replies?" must not be a
 * subquery per row (CLAUDE.md §10).
 *
 * The status half is the subtle part. `open` and `answered` FOLLOW the reply
 * count, but `resolved` and `hidden` do not: a resolved thread must not
 * reopen because somebody added a footnote, and a hidden one must not
 * resurface because somebody replied to it. `followsReplies()` is the single
 * place that distinction lives.
 */
final class RecalculateDiscussionCounters
{
    public function handle(Discussion $discussion): Discussion
    {
        /** @var object{count: int, last: string|null} $row */
        $row = DiscussionReply::query()
            ->counted()
            ->where('discussion_id', $discussion->id)
            ->selectRaw('COUNT(*) as count, MAX(created_at) as last')
            ->first();

        $count = (int) $row->count;

        $attributes = [
            'reply_count' => $count,
            'last_reply_at' => $row->last,
        ];

        if ($discussion->status->followsReplies()) {
            $attributes['status'] = $count > 0
                ? DiscussionStatus::Answered
                : DiscussionStatus::Open;
        }

        $discussion->forceFill($attributes)->save();

        return $discussion;
    }

    public function forDiscussionId(int $discussionId): void
    {
        $discussion = Discussion::find($discussionId);

        if ($discussion !== null) {
            $this->handle($discussion);
        }
    }
}
