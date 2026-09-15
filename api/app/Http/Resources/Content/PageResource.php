<?php

declare(strict_types=1);

namespace App\Http\Resources\Content;

use App\Domain\Content\Models\Page;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A page — for the stranger reading it and for the admin building it, from ONE
 * resource. `blocks` are the RENDERED blocks (`PageRenderer`): each keeps the
 * `props` the builder edits and gains `data` where it points at something, so
 * the builder's preview and the public page read the same shape and cannot
 * drift.
 *
 * The authoring keys are added only for `page.manage` and are ABSENT for a
 * stranger, not false (the `PostResource` rule; `PublicPagesTest` asserts it).
 *
 * @mixin Page
 */
final class PageResource extends BaseResource
{
    /** @param  list<array<string, mixed>>  $renderedBlocks */
    public function __construct($resource, private readonly array $renderedBlocks, private readonly bool $canManage = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $page = [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'blocks' => $this->renderedBlocks,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
        ];

        if (! $this->canManage) {
            return $page;
        }

        return $page + [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'published_at' => $this->published_at?->toIso8601String(),
            'is_home' => $this->isHome(),
            'show_in_nav' => $this->show_in_nav,
            // The address is locked once the page has been out.
            'can_edit_slug' => $this->published_at === null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
