<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Domain\Catalog\Actions\ClaimFreeDownload;
use App\Domain\Catalog\Exceptions\DownloadLocked;
use App\Domain\Catalog\Models\Download;
use App\Domain\Catalog\Queries\DownloadAccess;
use App\Domain\Media\Support\MediaUrlGenerator;
use App\Http\Resources\Catalog\DownloadListResource;
use App\Http\Resources\Catalog\DownloadResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Downloads as a member sees them: the shelf, what they own, and the file.
 */
final class DownloadCatalogController
{
    public function __construct(private readonly DownloadAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $downloads = Download::query()
            ->published()
            ->with(['file', 'thumbnail', 'product.prices'])
            ->orderByDesc('published_at')
            ->paginate(min((int) $request->integer('per_page', 12), 50));

        return ApiResponse::ok(DownloadListResource::collection($downloads));
    }

    /**
     * What this reader owns — including downloads since ARCHIVED. Archiving
     * takes a download off sale, never out of an owner's library.
     */
    public function mine(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $downloads = Download::query()
            // Same schema on both sides (grants and downloads are tenant
            // tables), so whereHas is safe here — unlike across the boundary.
            ->whereHas('grants', fn ($query) => $query->where('user_id', $userId)->whereNull('revoked_at'))
            ->with(['file', 'thumbnail'])
            ->orderBy('title')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return ApiResponse::ok(DownloadListResource::collection($downloads));
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $download = $this->visible($request, $slug);

        return ApiResponse::ok(DownloadResource::make($download->load(['file', 'thumbnail', 'product.prices'])));
    }

    /** A POST, so a lapsed academy's 402 applies: claiming is a write. */
    public function claim(Request $request, string $slug, ClaimFreeDownload $action): JsonResponse
    {
        $download = $this->visible($request, $slug);

        $action->handle($request->user(), $download);

        return ApiResponse::ok(DownloadResource::make($download->load(['file', 'thumbnail', 'product.prices'])));
    }

    /**
     * A fresh 15-minute signed link — the ONLY place one is minted (ADR-09).
     *
     * A GET, so it is never gated by a lapsed subscription: somebody who
     * bought a file keeps it, the same principle as a certificate. Unlimited,
     * by decision (docs/DOWNLOADS.md §1): the file is theirs, and a shared
     * link dies in fifteen minutes.
     */
    public function file(Request $request, string $slug, MediaUrlGenerator $urls): JsonResponse
    {
        $download = $this->visible($request, $slug);
        $download->loadMissing(['file', 'product']);

        $decision = $this->access->for($request->user(), $download);

        if (! $decision->granted) {
            // 423, not 403: they could legitimately own it (§ Phase 6).
            throw DownloadLocked::from($decision, $download);
        }

        // Guarded by DeleteMedia, so this means a draft with no file yet.
        if ($download->file === null) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::ok([
            'url' => $urls->signed($download->file),
            'expires_at' => $urls->expiresAt(),
        ]);
    }

    /** 404 for anything this reader may not even see — never 403 (§ API §2). */
    private function visible(Request $request, string $slug): Download
    {
        $download = Download::query()->where('slug', $slug)->first();

        if ($download === null || ! $this->access->isVisibleTo($request->user(), $download)) {
            throw new NotFoundHttpException;
        }

        return $download;
    }
}
