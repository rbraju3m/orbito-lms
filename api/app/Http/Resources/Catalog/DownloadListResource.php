<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Models\Download;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * The thin shape for catalogue, studio and library lists.
 *
 * NEVER a URL. `media.download` streams on the signature alone, so the only
 * access check a file gets is the one made when its link is minted — and a
 * list that minted one per row would hand out thirty links nobody asked for.
 * The fetch endpoint is the only place a link is made (docs/DOWNLOADS.md §4).
 *
 * @mixin Download
 */
final class DownloadListResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = strtoupper((string) config('orbito.currency.base'));

        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'subtitle' => $this->subtitle,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'published_at' => $this->published_at?->toIso8601String(),

            'pricing_model' => $this->pricing_model->value,
            'is_free' => $this->pricing_model->isFree(),

            // What the buyer is getting, without handing it to them.
            'file' => $this->whenLoaded('file', fn () => $this->file === null ? null : [
                'name' => $this->file->original_name,
                'mime' => $this->file->mime,
                'extension' => $this->file->extension,
                'size_bytes' => $this->file->size_bytes,
            ]),

            'thumbnail_url' => $this->whenLoaded('thumbnail', fn () => $this->thumbnail?->publicUrl()),

            // Display only; the charge is re-read at checkout (ADR-05). Null
            // for a free download, which has no product at all.
            'price' => $this->when(
                $this->resource->relationLoaded('product'),
                fn () => PriceView::for($this->resource->product, $currency),
            ),
        ];
    }
}
