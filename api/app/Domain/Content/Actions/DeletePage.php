<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Page;

/**
 * Deletes a page outright. Nothing refers to a page by id — a button links to
 * it by address — so there is no history to keep. Deleting the front page
 * leaves the standard front page in its place.
 */
final class DeletePage
{
    public function handle(Page $page): void
    {
        $page->delete();
    }
}
