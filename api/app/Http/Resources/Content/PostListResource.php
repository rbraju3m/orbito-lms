<?php

declare(strict_types=1);

namespace App\Http\Resources\Content;

use App\Domain\Content\Models\Post;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A post in a list — no body, which is most of a post's weight and none of a
 * list's. The same flag as `PostResource` adds the author's view of it.
 *
 * @mixin Post
 */
final class PostListResource extends BaseResource
{
    public function __construct($resource, private readonly bool $canManage = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $post = [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'cover_url' => $this->whenLoaded('cover', fn () => $this->cover?->publicUrl()),
            'author' => $this->whenLoaded('author', fn (): array => ['name' => $this->author->name ?? 'Former member']),
            'published_at' => $this->published_at?->toIso8601String(),
            'reading_minutes' => $this->readingMinutes(),
        ];

        if (! $this->canManage) {
            return $post;
        }

        return $post + [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_scheduled' => $this->isScheduled(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
