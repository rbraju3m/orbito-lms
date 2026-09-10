<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Data\BundleData;
use App\Domain\Catalog\Models\Bundle;
use App\Support\Html\RichTextSanitizer;
use Illuminate\Support\Facades\DB;

final class UpdateBundle
{
    public function __construct(
        private readonly SetBundleCourses $setCourses,
        private readonly RichTextSanitizer $sanitizer,
    ) {}

    /** @param  array<string, mixed>  $supplied  the keys actually present in the request */
    public function handle(Bundle $bundle, BundleData $data, array $supplied): Bundle
    {
        return DB::transaction(function () use ($bundle, $data, $supplied): Bundle {
            $map = [
                'title' => $data->title,
                'subtitle' => $data->subtitle,
                'description' => $this->sanitizer->clean($data->description),
                'thumbnail_media_id' => $data->thumbnailMediaId,
            ];

            // Only touch what the caller actually sent. A PATCH that omits a
            // field must leave it alone, not null it.
            foreach ($map as $column => $value) {
                if (array_key_exists($column, $supplied)) {
                    $bundle->{$column} = $value;
                }
            }

            $bundle->save();

            if (array_key_exists('course_ids', $supplied) && $data->courseIds !== null) {
                $this->setCourses->handle($bundle, $data->courseIds);
            }

            /*
             * The product carries the bundle's title, so a rename has to reach
             * it — otherwise a basket shows the old name. Not an event: the
             * product is a projection of this row, and a rename is not a fact
             * another context needs to hear about.
             */
            $bundle->loadMissing('product');
            $bundle->product?->update(['title' => $bundle->title]);

            return $bundle->fresh(['courses.product.prices', 'thumbnail', 'product.prices']) ?? $bundle;
        });
    }
}
