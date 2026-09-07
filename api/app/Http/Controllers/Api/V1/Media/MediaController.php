<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Media;

use App\Domain\Media\Actions\DeleteMedia;
use App\Domain\Media\Actions\StoreUploadedMedia;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Support\MediaUrlGenerator;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Http\Resources\Media\MediaResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MediaController
{
    public function store(StoreMediaRequest $request, StoreUploadedMedia $action): JsonResponse
    {
        $media = $action->handle(
            $request->user(),
            $request->file('file'),
            $request->collection(),
        );

        return ApiResponse::created(MediaResource::make($media));
    }

    /** Mints a fresh signed URL for a private file, after checking access. */
    public function url(Media $media, MediaUrlGenerator $urls): JsonResponse
    {
        Gate::authorize('view', $media);

        return ApiResponse::ok([
            'url' => $urls->for($media),
            'expires_at' => $media->disk->isPrivate() ? $urls->expiresAt() : null,
        ]);
    }

    /**
     * Streams a private file. Reached only through a signed URL, so the
     * signature is the credential — see the `signed` middleware on the route.
     */
    public function download(Media $media): StreamedResponse
    {
        return Storage::disk($media->disk->value)->download($media->path, $media->original_name);
    }

    public function destroy(Media $media, DeleteMedia $action): JsonResponse
    {
        Gate::authorize('delete', $media);

        $action->handle($media);

        return ApiResponse::noContent();
    }
}
