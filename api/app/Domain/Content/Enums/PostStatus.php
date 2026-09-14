<?php

declare(strict_types=1);

namespace App\Domain\Content\Enums;

/**
 * A post is written, then published. There is no review state — the people who
 * hold `post.manage` are the people who would review it — and no archive: a
 * post taken down goes back to draft.
 *
 * SCHEDULED is not a status. It is a published post whose `published_at` is
 * still ahead, derived from the clock (`Post::published()`), so it cannot be
 * late the way a status flipped by a sweeper can.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }
}
