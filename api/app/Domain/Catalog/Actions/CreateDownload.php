<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Data\DownloadData;
use App\Domain\Catalog\Enums\DownloadPricing;
use App\Domain\Catalog\Enums\DownloadStatus;
use App\Domain\Catalog\Events\DownloadCreated;
use App\Domain\Catalog\Models\Download;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Queries\PlanLimits;
use App\Support\Html\RichTextSanitizer;
use Illuminate\Support\Facades\DB;

final class CreateDownload
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly RichTextSanitizer $sanitizer,
    ) {}

    public function handle(DownloadData $data): Download
    {
        // A draft counts, as a draft course does: capping only published ones
        // would let an academy stock its whole shelf and release it one at a
        // time. The academy's own decision, so it is the right party to stop.
        $this->limits->assert(UsageMetric::Downloads);

        $download = DB::transaction(function () use ($data): Download {
            $download = new Download([
                'title' => $data->title ?? 'Untitled download',
                'subtitle' => $data->subtitle,
                // Sanitised on WRITE, never on render (§ Phase 6).
                'description' => $this->sanitizer->clean($data->description),
                'media_id' => $data->mediaId,
                'thumbnail_media_id' => $data->thumbnailMediaId,
                'pricing_model' => DownloadPricing::from($data->pricingModel ?? DownloadPricing::OneTime->value),
            ]);

            // Never mass-assignable: lifecycle is not client input.
            $download->status = DownloadStatus::Draft;
            $download->save();

            return $download;
        });

        DownloadCreated::dispatch($download);

        return $download->fresh(['file', 'thumbnail', 'product.prices']) ?? $download;
    }
}
