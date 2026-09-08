<?php

declare(strict_types=1);

namespace App\Http\Resources\Engagement;

use App\Domain\Engagement\Models\Discussion;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One thread.
 *
 * `reply_count` and `last_reply_at` come off the stored columns, never a
 * count on the relation — that is the whole reason they are stored
 * (CLAUDE.md §10). A list of thirty threads must be thirty rows, not thirty
 * subqueries.
 *
 * @mixin Discussion
 */
final class DiscussionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->uuid,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'title' => $this->title,
            'body' => $this->body,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_resolved' => $this->isResolved(),
            'is_pinned' => $this->is_pinned,
            // Only a question can be answered, so the UI does not offer it on
            // a comment and then have the server refuse.
            'is_answerable' => $this->type->isAnswerable(),

            'reply_count' => $this->reply_count,
            'last_reply_at' => $this->last_reply_at?->toIso8601String(),
            'accepted_reply_id' => $this->whenLoaded(
                'replies',
                fn () => $this->replies->firstWhere('id', $this->accepted_reply_id)?->uuid,
            ),

            'author' => [
                'name' => $this->whenLoaded('user', fn () => $this->user->name ?? 'Former member'),
                'is_you' => $viewer !== null && $this->user_id === $viewer->id,
            ],

            // Which lesson it hangs off, when it hangs off one at all.
            'item' => $this->whenLoaded('item', fn () => $this->item === null ? null : [
                'id' => $this->item->uuid,
                'title' => $this->item->title,
            ]),

            'replies' => DiscussionReplyResource::collection($this->whenLoaded('replies')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
