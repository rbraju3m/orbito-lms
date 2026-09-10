<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Studio;

use App\Domain\Catalog\Actions\CreateDownload;
use App\Domain\Catalog\Actions\DeleteDownload;
use App\Domain\Catalog\Actions\UpdateDownload;
use App\Domain\Catalog\Models\Download;
use App\Domain\Catalog\Support\DownloadPublishChecklist;
use App\Http\Requests\Catalog\StoreDownloadRequest;
use App\Http\Requests\Catalog\UpdateDownloadRequest;
use App\Http\Resources\Catalog\DownloadListResource;
use App\Http\Resources\Catalog\DownloadResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Stocking the shelf. The buyer's side is `DownloadCatalogController` (ADR-06). */
final class DownloadController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('create', Download::class);

        $downloads = Download::query()
            ->with(['file', 'thumbnail', 'product.prices'])
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->value()),
            )
            ->latest('updated_at')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return ApiResponse::ok(DownloadListResource::collection($downloads));
    }

    public function store(StoreDownloadRequest $request, CreateDownload $action): JsonResponse
    {
        Gate::authorize('create', Download::class);

        return ApiResponse::created(DownloadResource::make($action->handle($request->toData())));
    }

    public function show(Request $request, Download $download, DownloadPublishChecklist $checklist): JsonResponse
    {
        Gate::authorize('update', $download);

        $download->load(['file', 'thumbnail', 'product.prices']);

        return ApiResponse::ok([
            ...DownloadResource::make($download)->resolve($request),
            // The checklist the author acts on, from the rules publish
            // enforces (§10). Detail only: several queries per download.
            'checklist' => $checklist->evaluate($download),
            'available_actions' => array_map(
                static fn ($status): string => $status->value,
                $download->status->allowedTransitions(),
            ),
        ]);
    }

    public function update(UpdateDownloadRequest $request, Download $download, UpdateDownload $action): JsonResponse
    {
        Gate::authorize('update', $download);

        return ApiResponse::ok(DownloadResource::make(
            $action->handle($download, $request->toData(), $request->suppliedKeys())
        ));
    }

    public function destroy(Download $download, DeleteDownload $action): JsonResponse
    {
        Gate::authorize('delete', $download);

        $action->handle($download);

        return ApiResponse::noContent();
    }
}
