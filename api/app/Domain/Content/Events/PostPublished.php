<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

use App\Domain\Content\Models\Post;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A post went live on the academy's public site — the transition from draft,
 * and only when its time had already come. A scheduled post does not fire
 * this when it is scheduled (`ChangePostStatus::publish`).
 */
final class PostPublished
{
    use Dispatchable;

    public function __construct(public readonly Post $post) {}
}
