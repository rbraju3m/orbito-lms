<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Studio;

use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\Download;
use App\Domain\Commerce\Actions\SetProductPrice;
use App\Domain\Commerce\Actions\SyncBundleProduct;
use App\Domain\Commerce\Actions\SyncCourseProduct;
use App\Domain\Commerce\Actions\SyncDownloadProduct;
use App\Domain\Commerce\Exceptions\PricingRejected;
use App\Domain\Commerce\Models\Product;
use App\Http\Requests\Catalog\SetPriceRequest;
use App\Http\Resources\Commerce\ProductPriceResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * What a course or a bundle costs.
 *
 * Two endpoints, one Action, because the rules about money do not change with
 * what is being sold — and because `SetProductPrice` is the only write path
 * for `product_prices`, so every one of those rules lives in one place.
 *
 * Its own permission (`course.price.*`, `bundle.manage`) rather than riding on
 * `update`: what a course EARNS is a different decision from what it says.
 */
final class PricingController
{
    public function __construct(
        private readonly SetProductPrice $setPrice,
        private readonly SyncCourseProduct $syncCourse,
        private readonly SyncBundleProduct $syncBundle,
        private readonly SyncDownloadProduct $syncDownload,
    ) {}

    public function course(SetPriceRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('price', $course);

        /*
         * A free course has no product to hang a price on. Refusing here — and
         * naming the field to change — beats creating a product the course's
         * own `pricing_model` says should not exist.
         */
        $product = $this->syncCourse->handle($course);

        if ($product === null) {
            throw PricingRejected::purchasableIsFree();
        }

        return $this->respond($request, $product);
    }

    public function bundle(SetPriceRequest $request, Bundle $bundle): JsonResponse
    {
        Gate::authorize('price', $bundle);

        // Idempotent, and the bundle's product exists from creation — this is
        // belt and braces for one made before that listener existed.
        return $this->respond($request, $this->syncBundle->handle($bundle));
    }

    public function download(SetPriceRequest $request, Download $download): JsonResponse
    {
        Gate::authorize('price', $download);

        // A free download has no product, exactly like a free course.
        $product = $this->syncDownload->handle($download);

        if ($product === null) {
            throw PricingRejected::purchasableIsFree();
        }

        return $this->respond($request, $product);
    }

    private function respond(SetPriceRequest $request, Product $product): JsonResponse
    {
        $price = $this->setPrice->handle(
            $product,
            $request->string('currency')->value(),
            (int) $request->integer('amount_minor'),
            $request->filled('sale_amount_minor') ? (int) $request->integer('sale_amount_minor') : null,
            $request->date('sale_starts_at'),
            $request->date('sale_ends_at'),
        );

        /*
         * Not `PriceView`. That is the CATALOGUE's answer and returns null for
         * a product that is not sellable — which a draft course's always is,
         * so an author would price their course and be handed `null`. This is
         * the authoring view: what is stored, whether or not it is on sale
         * yet.
         */
        return ApiResponse::ok(ProductPriceResource::make($price));
    }
}
