<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Data\BundleData;
use App\Domain\Catalog\Enums\BundleStatus;
use App\Domain\Catalog\Events\BundleCreated;
use App\Domain\Catalog\Models\Bundle;
use App\Support\Html\RichTextSanitizer;
use Illuminate\Support\Facades\DB;

final class CreateBundle
{
    public function __construct(
        private readonly SetBundleCourses $setCourses,
        private readonly RichTextSanitizer $sanitizer,
    ) {}

    public function handle(BundleData $data): Bundle
    {
        $bundle = DB::transaction(function () use ($data): Bundle {
            $bundle = new Bundle([
                'title' => $data->title ?? 'Untitled bundle',
                'subtitle' => $data->subtitle,
                // Sanitised on WRITE, never on render, so the stored value is
                // safe for the API, mobile and exports alike (§ Phase 6).
                'description' => $this->sanitizer->clean($data->description),
                'thumbnail_media_id' => $data->thumbnailMediaId,
            ]);

            // Never mass-assignable: lifecycle is not client input.
            $bundle->status = BundleStatus::Draft;
            $bundle->save();

            if ($data->courseIds !== null) {
                $this->setCourses->handle($bundle, $data->courseIds);
            }

            return $bundle;
        });

        BundleCreated::dispatch($bundle);

        return $bundle->fresh(['courses', 'thumbnail', 'product.prices']) ?? $bundle;
    }
}
