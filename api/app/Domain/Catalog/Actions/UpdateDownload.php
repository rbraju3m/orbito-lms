<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Data\DownloadData;
use App\Domain\Catalog\Enums\DownloadPricing;
use App\Domain\Catalog\Events\DownloadPricingChanged;
use App\Domain\Catalog\Models\Download;
use App\Support\Html\RichTextSanitizer;
use Illuminate\Support\Facades\DB;

/**
 * Replacing `media_id` on a published download is allowed, and reaches every
 * buyer on their next fetch: a download is a LIVE file, and the order line is
 * the snapshot (docs/DOWNLOADS.md §4).
 */
final class UpdateDownload
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    /** @param  array<string, mixed>  $supplied  the keys actually present in the request */
    public function handle(Download $download, DownloadData $data, array $supplied): Download
    {
        $pricingWas = $download->pricing_model;

        $updated = DB::transaction(function () use ($download, $data, $supplied): Download {
            $map = [
                'title' => $data->title,
                'subtitle' => $data->subtitle,
                'description' => $this->sanitizer->clean($data->description),
                'media_id' => $data->mediaId,
                'thumbnail_media_id' => $data->thumbnailMediaId,
                'pricing_model' => $data->pricingModel !== null ? DownloadPricing::from($data->pricingModel) : null,
            ];

            // Only what the caller sent. A PATCH that omits a field must leave
            // it alone, not null it.
            foreach ($map as $column => $value) {
                if (array_key_exists($column, $supplied)) {
                    $download->{$column} = $value;
                }
            }

            $download->save();

            // The product carries the title, so a rename has to reach it or a
            // basket shows the old name.
            $download->loadMissing('product');
            $download->product?->update(['title' => $download->title]);

            return $download->fresh(['file', 'thumbnail', 'product.prices']) ?? $download;
        });

        // Commerce decides whether there is something to sell; Catalog only
        // announces the change, and only when there was one.
        if ($updated->pricing_model !== $pricingWas) {
            DownloadPricingChanged::dispatch($updated, $pricingWas, $updated->pricing_model);
        }

        return $updated;
    }
}
