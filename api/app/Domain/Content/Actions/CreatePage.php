<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Models\Page;
use App\Domain\Identity\Models\User;

/** A new page — a DRAFT with no blocks. Publishing is its own decision. */
final class CreatePage
{
    /** @param  array<string, mixed>  $attributes  validated by `StorePageRequest` */
    public function handle(User $author, array $attributes): Page
    {
        return Page::create([
            'title' => $attributes['title'],
            'slug' => $attributes['slug'] ?? null,
            'author_id' => $author->id,
            'blocks' => [],
            'status' => PageStatus::Draft,
        ]);
    }
}
