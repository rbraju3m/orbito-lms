<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Exceptions\PageRejected;
use App\Domain\Content\Models\Page;

/**
 * Every move a page's status makes (§ Patterns established in Phase 4).
 *
 * Fires no event: nothing in the product consumes a page being published, and
 * an event lands with its consumer (docs/EVENTS.md §3).
 */
final class ChangePageStatus
{
    public function publish(Page $page): Page
    {
        if ($page->blockList() === []) {
            throw PageRejected::empty();
        }

        if ($page->status !== PageStatus::Published) {
            $page->status = PageStatus::Published;
            // Set once: the first publication is what locks the address.
            $page->published_at ??= now();
            $page->save();
        }

        return $page;
    }

    /** Off the public site. A front page taken down leaves the standard front page in its place. */
    public function unpublish(Page $page): Page
    {
        if ($page->status !== PageStatus::Draft) {
            $page->status = PageStatus::Draft;
            $page->save();
        }

        return $page;
    }
}
