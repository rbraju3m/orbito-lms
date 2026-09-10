<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

/**
 * Validated download attributes crossing into the domain. Actions never see a
 * Request, and nothing here is mass-assigned from raw input.
 */
final readonly class DownloadData
{
    public function __construct(
        public ?string $title = null,
        public ?string $subtitle = null,
        public ?string $description = null,
        public ?int $mediaId = null,
        public ?int $thumbnailMediaId = null,
        public ?string $pricingModel = null,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromArray(array $input): self
    {
        return new self(
            title: $input['title'] ?? null,
            subtitle: array_key_exists('subtitle', $input) ? $input['subtitle'] : null,
            description: array_key_exists('description', $input) ? $input['description'] : null,
            mediaId: isset($input['media_id']) ? (int) $input['media_id'] : null,
            thumbnailMediaId: isset($input['thumbnail_media_id']) ? (int) $input['thumbnail_media_id'] : null,
            pricingModel: $input['pricing_model'] ?? null,
        );
    }
}
