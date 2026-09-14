<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Events\PostPublished;
use App\Domain\Content\Exceptions\PostRejected;
use App\Domain\Content\Models\Post;
use Carbon\CarbonInterface;

/** Every move a post's status makes. One place owns them (§ Patterns established in Phase 4). */
final class ChangePostStatus
{
    /**
     * Published now, or at `$at`. A time in the future SCHEDULES it: the post
     * is published, and `Post::published()` keeps it off the public site until
     * the clock reaches it.
     *
     * `PostPublished` fires on the TRANSITION and only for a post that is live
     * at that moment. A scheduled post fires nothing, because an integration
     * told "published" would announce a page that does not exist yet; turning
     * that on means a sweep at the scheduled time, which is its own slice
     * (docs/BLOG.md §6). Publishing an already-published post only moves its
     * time.
     */
    public function publish(Post $post, ?CarbonInterface $at = null): Post
    {
        if (trim(html_entity_decode(strip_tags((string) $post->body))) === '') {
            throw PostRejected::emptyBody();
        }

        $wasPublished = $post->status === PostStatus::Published;

        $post->status = PostStatus::Published;

        if ($at !== null) {
            $post->published_at = $at;
        } else {
            // Set once: a post taken down and put back keeps the date it first
            // went out, so it does not jump to the top of the list.
            $post->published_at ??= now();
        }

        $post->save();

        if (! $wasPublished && $post->isLive()) {
            PostPublished::dispatch($post);
        }

        return $post;
    }

    /** Back to draft, off the public site. The date it first went out is kept. */
    public function unpublish(Post $post): Post
    {
        if ($post->status !== PostStatus::Draft) {
            $post->status = PostStatus::Draft;
            $post->save();
        }

        return $post;
    }
}
