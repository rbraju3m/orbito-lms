<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\Actions\AnnouncePost;
use App\Domain\Content\Models\Post;
use App\Support\Console\RunsForEveryTenant;
use Illuminate\Console\Command;

/**
 * Announces a scheduled post when its time comes (docs/BLOG.md §4).
 *
 * The post itself needs nothing: `Post::published()` compares against the
 * clock, so it appears on the public site on its own. What cannot happen on
 * its own is TELLING anybody — an event needs something to fire it, which is
 * the one place a clock-derived state needs a job. Every minute, so a
 * `post.published` webhook lands within a minute of the page.
 *
 * No floor on the window, unlike `live:remind`. A reminder about a class that
 * has finished is wrong; a post announced an hour late because the scheduler
 * was down is still true. The migration that added the column marked every
 * post already live as announced, so there is no backlog to flood out.
 */
final class AnnounceScheduledPosts extends Command
{
    use RunsForEveryTenant;

    protected $signature = 'blog:announce';

    protected $description = 'Announce scheduled blog posts whose time has come, in every academy';

    public function handle(AnnouncePost $announce): int
    {
        $announced = 0;

        $failed = $this->forEachTenant(function () use ($announce, &$announced): void {
            Post::query()
                ->published()
                ->whereNull('announced_at')
                ->orderBy('published_at')
                ->orderBy('id')
                ->each(function (Post $post) use ($announce, &$announced): void {
                    if ($announce->handle($post)) {
                        $announced++;
                    }
                });
        });

        $this->info("Announced {$announced} post(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
