<?php

declare(strict_types=1);

namespace App\Http\Resources\Engagement;

use App\Domain\Engagement\Models\DiscussionReply;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin DiscussionReply
 */
final class DiscussionReplyResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->uuid,
            'body' => $this->body,
            'parent_id' => $this->whenLoaded('parent', fn () => $this->parent?->uuid),
            // Read off the stored flag, not recomputed: somebody who answered
            // as an instructor and later lost the role still answered as one.
            'is_instructor_reply' => $this->is_instructor_reply,
            'author' => [
                'name' => $this->whenLoaded('user', fn () => $this->user->name ?? 'Former member'),
                'is_you' => $viewer !== null && $this->user_id === $viewer->id,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
