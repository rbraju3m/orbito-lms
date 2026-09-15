<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\PageRejected;
use App\Domain\Content\Models\Page;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Which page is the academy's front page — at most one.
 *
 * The rule is the UNIQUE index on `pages.home_key`, not this class: the old
 * front page is cleared and the new one set in one transaction, and two
 * people choosing different pages at the same moment cannot both win — the
 * second insert hits the constraint and is told so (§ Patterns established in
 * Phase 14: a constraint, not a check).
 *
 * A DRAFT may be chosen. The public site shows a front page only once it is
 * published, and falls back to the standard front page until then — which is
 * how an academy builds its new front page without taking the old one down.
 */
final class SetHomePage
{
    public function handle(Page $page): Page
    {
        if ($page->isHome()) {
            return $page;
        }

        try {
            DB::transaction(function () use ($page): void {
                Page::query()->where('home_key', Page::HOME)->update(['home_key' => null]);

                $page->home_key = Page::HOME;
                $page->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw PageRejected::homeTaken();
        }

        return $page;
    }

    /** No front page chosen: the public site shows its standard one. */
    public function clear(Page $page): Page
    {
        if ($page->isHome()) {
            $page->home_key = null;
            $page->save();
        }

        return $page;
    }
}
