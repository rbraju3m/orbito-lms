<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Domain\Catalog\Models\Bundle;
use App\Http\Resources\Catalog\BundleListResource;
use App\Http\Resources\Catalog\BundleResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Bundles as a buyer sees them: published only, addressed by slug.
 *
 * A separate controller from the studio's, because the audience differs and
 * so do the queries — this one never loads a checklist and never sees a
 * draft. Same split as `CourseCatalogController` (ADR-06).
 */
final class BundleCatalogController
{
    public function index(Request $request): JsonResponse
    {
        $bundles = Bundle::query()
            ->published()
            ->withCount('courses')
            ->with(['thumbnail', 'product.prices'])
            ->orderByDesc('published_at')
            ->paginate(min((int) $request->integer('per_page', 12), 50));

        return ApiResponse::ok(BundleListResource::collection($bundles));
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $bundle = Bundle::query()
            ->published()
            ->where('slug', $slug)
            // Course prices are what `parts_total_minor` is computed from, so
            // they are loaded here rather than discovered one lazy query at a
            // time — strict mode forbids the latter anyway.
            ->with(['courses.product.prices', 'thumbnail', 'product.prices'])
            ->first();

        // Not 403: a bundle nobody may see is indistinguishable from one that
        // does not exist, and the status code must not leak which.
        if ($bundle === null) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::ok(BundleResource::make($bundle));
    }
}
