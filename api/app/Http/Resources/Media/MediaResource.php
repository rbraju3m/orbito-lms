<?php

declare(strict_types=1);

namespace App\Http\Resources\Media;

use App\Domain\Media\Models\Media;
use App\Domain\Media\Support\MediaUrlGenerator;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin Media
 */
final class MediaResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'collection' => $this->collection,
            'mime' => $this->mime,
            'extension' => $this->extension,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'duration_seconds' => $this->duration_seconds,
            'original_name' => $this->original_name,
            'is_private' => $this->disk->isPrivate(),
            'created_at' => $this->created_at?->toIso8601String(),

            // Public files get a permanent URL; private ones a short-lived
            // signed URL. There is no code path that hands out a permanent URL
            // for private content (ADR-09).
            'url' => app(MediaUrlGenerator::class)->for($this->resource),
            'url_expires_at' => $this->disk->isPrivate()
                ? app(MediaUrlGenerator::class)->expiresAt()
                : null,
        ];
    }
}
