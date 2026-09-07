<?php

declare(strict_types=1);

namespace App\Domain\Media\Support;

use App\Domain\Media\Models\Media;
use Illuminate\Support\Facades\URL;

/**
 * Public files get a permanent CDN-friendly URL. Private files get a
 * short-lived signed URL, minted only after access has been checked (ADR-09).
 *
 * There is no code path that returns a permanent URL for private content.
 */
final class MediaUrlGenerator
{
    public function __construct(private readonly int $ttlMinutes = 15) {}

    public function for(Media $media): string
    {
        return $media->publicUrl() ?? $this->signed($media);
    }

    public function signed(Media $media): string
    {
        return URL::temporarySignedRoute(
            'media.download',
            now()->addMinutes($this->ttlMinutes),
            ['media' => $media->uuid],
        );
    }

    public function expiresAt(): string
    {
        return now()->addMinutes($this->ttlMinutes)->toIso8601String();
    }
}
