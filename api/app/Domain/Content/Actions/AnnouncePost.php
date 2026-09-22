<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Events\PostPublished;
use App\Domain\Content\Models\Post;

/**
 * Tells the rest of the system a post is out — once per post, ever.
 *
 * Two callers: `ChangePostStatus::publish` for a post live the moment it is
 * published, and `blog:announce` for one whose scheduled time has come. One
 * definition of "announce", so the two cannot disagree about what counts.
 *
 * ONCE, EVER. A post taken down to fix a typo and put back is not news, and an
 * integration that posts "new article" somewhere would post it twice. The same
 * reason a post's slug locks at its first publication.
 *
 * The claim is a conditional UPDATE, not a check-then-write: the sweep can run
 * on more than one host, and a publish request can land while it runs. Only
 * the caller whose update matched a row fires. Claimed BEFORE dispatching, so
 * a crash between the two loses one announcement rather than doubling every
 * retry (§ Patterns established in Phase 15).
 */
final class AnnouncePost
{
    /** Whether this call announced it. */
    public function handle(Post $post): bool
    {
        if (! $post->isLive() || $post->announced_at !== null) {
            return false;
        }

        $now = now();

        $claimed = Post::query()
            ->whereKey($post->getKey())
            ->where('status', PostStatus::Published)
            ->whereNull('announced_at')
            ->update(['announced_at' => $now]);

        if ($claimed === 0) {
            return false;
        }

        $post->setAttribute('announced_at', $now);
        $post->syncOriginalAttribute('announced_at');

        PostPublished::dispatch($post);

        return true;
    }
}
