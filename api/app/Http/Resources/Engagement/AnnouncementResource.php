<?php

declare(strict_types=1);

namespace App\Http\Resources\Engagement;

use App\Domain\Engagement\Models\Announcement;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin Announcement
 */
final class AnnouncementResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'body' => $this->body,
            'is_published' => $this->isPublished(),
            'published_at' => $this->published_at?->toIso8601String(),
            // What was INTENDED. Nothing consumes it until the notifications
            // slice, and the studio UI needs to show the author's choice.
            'notify' => $this->notify,
            'author' => [
                'name' => $this->whenLoaded('author', fn () => $this->author->name ?? 'Former member'),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
