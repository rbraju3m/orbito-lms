<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Models\Download;
use App\Domain\Catalog\Queries\DownloadAccess;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One download, in full — still never a URL (see DownloadListResource).
 *
 * `can_fetch` is per-reader and lives HERE, not on the list (§16): it is an
 * access decision per row, and a page of thirty would be thirty of them. It
 * comes from `DownloadAccess`, the same class the fetch endpoint asks, so the
 * button and the server cannot disagree.
 *
 * @mixin Download
 */
final class DownloadResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...DownloadListResource::make($this->resource)->resolve($request),
            'description' => $this->description,
            'can_fetch' => app(DownloadAccess::class)->for($request->user(), $this->resource)->granted,
        ];
    }
}
