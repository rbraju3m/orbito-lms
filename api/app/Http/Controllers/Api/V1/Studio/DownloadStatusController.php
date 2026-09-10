<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Studio;

use App\Domain\Catalog\Actions\ChangeDownloadStatus;
use App\Domain\Catalog\Enums\DownloadStatus;
use App\Domain\Catalog\Models\Download;
use App\Http\Resources\Catalog\DownloadResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Lifecycle as sub-resources — the same shape as `BundleStatusController`. */
final class DownloadStatusController
{
    public function __construct(private readonly ChangeDownloadStatus $action) {}

    public function publish(Request $request, Download $download): JsonResponse
    {
        Gate::authorize('publish', $download);

        return $this->respond($request, $download, DownloadStatus::Published);
    }

    public function unpublish(Request $request, Download $download): JsonResponse
    {
        Gate::authorize('publish', $download);

        return $this->respond($request, $download, DownloadStatus::Draft);
    }

    /** Takes it off sale. Owners keep it — see DownloadAccess. */
    public function archive(Request $request, Download $download): JsonResponse
    {
        Gate::authorize('delete', $download);

        return $this->respond($request, $download, DownloadStatus::Archived);
    }

    private function respond(Request $request, Download $download, DownloadStatus $target): JsonResponse
    {
        $updated = $this->action->handle($download, $target, $request->user());

        return ApiResponse::ok(DownloadResource::make($updated->load(['file', 'thumbnail', 'product.prices'])));
    }
}
