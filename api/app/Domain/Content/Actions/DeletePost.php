<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Post;

/**
 * Deletes a post outright. Nothing refers to a post — no order, no enrolment,
 * no progress — so there is no history to keep, and a published post somebody
 * wants gone is unpublished first if they only want it hidden.
 */
final class DeletePost
{
    public function handle(Post $post): void
    {
        $post->delete();
    }
}
