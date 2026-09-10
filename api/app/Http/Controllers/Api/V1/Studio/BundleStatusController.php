<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Studio;

use App\Domain\Catalog\Actions\ChangeBundleStatus;
use App\Domain\Catalog\Enums\BundleStatus;
use App\Domain\Catalog\Models\Bundle;
use App\Http\Resources\Catalog\BundleResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lifecycle transitions as sub-resources, not a verb in a query string
 * (docs/API.md §1). Same shape as `CourseStatusController`.
 */
final class BundleStatusController
{
    public function __construct(private readonly ChangeBundleStatus $action) {}

    public function publish(Request $request, Bundle $bundle): JsonResponse
    {
        Gate::authorize('publish', $bundle);

        return $this->respond($request, $bundle, BundleStatus::Published);
    }

    /** Pulls a live bundle back to draft without losing it. */
    public function unpublish(Request $request, Bundle $bundle): JsonResponse
    {
        Gate::authorize('publish', $bundle);

        return $this->respond($request, $bundle, BundleStatus::Draft);
    }

    public function archive(Request $request, Bundle $bundle): JsonResponse
    {
        Gate::authorize('delete', $bundle);

        return $this->respond($request, $bundle, BundleStatus::Archived);
    }

    private function respond(Request $request, Bundle $bundle, BundleStatus $target): JsonResponse
    {
        $updated = $this->action->handle($bundle, $target, $request->user());

        return ApiResponse::ok(
            BundleResource::make($updated->load(['courses.product.prices', 'thumbnail', 'product.prices']))
        );
    }
}
