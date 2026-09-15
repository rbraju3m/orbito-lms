<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Page;
use App\Domain\Content\Support\PageBlocks;

/**
 * Replaces a page's whole block list with the one the builder sent.
 *
 * Whole, not a delta (§ Patterns established in Phase 5): two people editing a
 * page each save the list they see, and the later save wins cleanly rather
 * than interleaving into a page neither of them built. Normalised on the way
 * in, so what is stored is exactly what `PageRenderer` expects.
 */
final class SavePageBlocks
{
    public function __construct(private readonly PageBlocks $blocks) {}

    /** @param  array<int, mixed>  $blocks  validated by `SavePageBlocksRequest` */
    public function handle(Page $page, array $blocks): Page
    {
        $page->blocks = $this->blocks->normalise($blocks);
        $page->save();

        return $page;
    }
}
