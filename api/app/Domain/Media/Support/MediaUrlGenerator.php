<?php

declare(strict_types=1);

namespace App\Domain\Media\Support;

use App\Domain\Media\Models\Media;
use Illuminate\Support\Facades\URL;
use Stancl\Tenancy\Contracts\Tenant;

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

    /**
     * The download route has no authenticated user — a <video> tag cannot send
     * a bearer token — so the academy has to travel in the URL for the request
     * to know which schema the media row is even in.
     *
     * It is safe there because the signature covers every query parameter:
     * changing `tenant` to point at another academy invalidates the link. This
     * is the same shape the payment-webhook callbacks will need in Phase 10.
     */
    public function signed(Media $media): string
    {
        /** @var Tenant|null $tenant */
        $tenant = tenancy()->tenant;

        return URL::temporarySignedRoute(
            'media.download',
            now()->addMinutes($this->ttlMinutes),
            array_filter([
                'media' => $media->uuid,
                'tenant' => $tenant?->getTenantKey(),
            ]),
        );
    }

    public function expiresAt(): string
    {
        return now()->addMinutes($this->ttlMinutes)->toIso8601String();
    }
}
