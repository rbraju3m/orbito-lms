<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Page;

/**
 * A page's settings: its title, address, header link and search snippet. Never
 * its blocks (`SavePageBlocks`), its status (`ChangePageStatus`) or whether it
 * is the front page (`SetHomePage`) — each of those is its own act.
 */
final class UpdatePage
{
    /** @param  array<string, mixed>  $attributes  validated by `UpdatePageRequest` */
    public function handle(Page $page, array $attributes): Page
    {
        $page->fill(array_intersect_key(
            $attributes,
            array_flip(['title', 'slug', 'show_in_nav', 'seo_title', 'seo_description']),
        ))->save();

        return $page;
    }
}
