<?php

declare(strict_types=1);

namespace App\Http\Resources\Content;

use App\Domain\Content\Models\Page;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A page in the ADMIN list — no blocks, and nothing resolved. Never served to
 * a stranger: the public site reads pages one at a time, by address.
 *
 * @mixin Page
 */
final class PageListResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_home' => $this->isHome(),
            'show_in_nav' => $this->show_in_nav,
            'block_count' => count($this->blockList()),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
