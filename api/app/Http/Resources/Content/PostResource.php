<?php

declare(strict_types=1);

namespace App\Http\Resources\Content;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Models\Post;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A whole post — for the stranger reading it on the public site and for the
 * author editing it, from ONE resource (§ Patterns established in Phase 16:
 * serve a stranger the same resource, not a smaller one).
 *
 * The authoring block is keyed on a flag the controller passes, like
 * `WebinarResource`, and is ABSENT for a stranger rather than false: a
 * stranger has no draft to ask about. `PublicBlogTest` asserts the absence so
 * a key added to that block cannot leak by being forgotten.
 *
 * @mixin Post
 */
final class PostResource extends BaseResource
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
            'body' => $this->body,
            'cover_url' => $this->whenLoaded('cover', fn () => $this->cover?->publicUrl()),
            'author' => $this->whenLoaded('author', fn (): array => ['name' => $this->author->name ?? 'Former member']),
            'published_at' => $this->published_at?->toIso8601String(),
            'reading_minutes' => $this->readingMinutes(),
            // What a search result and a shared link show; the page falls back
            // to the title and the excerpt when these are empty.
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
        ];

        // Absent for a stranger, not false: added only for the people who
        // write the blog.
        if (! $this->canManage) {
            return $post;
        }

        return $post + [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_scheduled' => $this->isScheduled(),
            // The address is locked once it has been out (`UpdatePostRequest`).
            'can_edit_slug' => $this->status === PostStatus::Draft && $this->published_at === null,
            'cover_media_ref' => $this->cover_media_id,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
