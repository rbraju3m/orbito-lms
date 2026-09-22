<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

use App\Domain\Content\Models\Post;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A post went live on the academy's public site. Once per post, ever — never
 * again for a post taken down and put back — and never before it is live: a
 * scheduled post fires when its time comes, from `blog:announce`. Both paths
 * go through `AnnouncePost`.
 */
final class PostPublished
{
    use Dispatchable;

    public function __construct(public readonly Post $post) {}
}
