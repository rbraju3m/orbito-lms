<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Enums\ProductStatus;
use App\Domain\Commerce\Models\Product;

/**
 * Keeps a course's sellable twin in step with the course.
 *
 * A course is not a product — it is a thing a product can point at. Keeping
 * them separate is what lets a bundle (P16) sell three courses through the
 * same checkout without any of them knowing.
 *
 * Idempotent, because it runs on every relevant course change.
 */
final class SyncCourseProduct
{
    public function handle(Course $course): ?Product
    {
        // A free course has nothing to sell. Enrolment goes straight through
        // EnrollInCourse, and a £0 product would only be something to get
        // wrong later.
        if ($course->pricing_model === PricingModel::Free) {
            Product::query()
                ->where('purchasable_type', $course->getMorphClass())
                ->where('purchasable_id', $course->id)
                ->update(['status' => ProductStatus::Inactive]);

            return null;
        }

        $product = Product::query()->firstOrNew([
            'purchasable_type' => $course->getMorphClass(),
            'purchasable_id' => $course->id,
        ]);

        $product->fill([
            'title' => $course->title,
            /*
             * Sellable only while the course is live. An unpublished course
             * that stayed purchasable would take money for something nobody
             * can open — and the access grant would succeed, which is worse
             * than failing.
             */
            'status' => $course->status->isLive() ? ProductStatus::Active : ProductStatus::Inactive,
        ])->save();

        return $product;
    }
}
