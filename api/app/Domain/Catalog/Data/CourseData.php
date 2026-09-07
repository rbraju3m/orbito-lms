<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

/**
 * Validated course attributes crossing into the domain. Actions never see a
 * Request, and nothing here is mass-assigned from raw input.
 */
final readonly class CourseData
{
    /**
     * @param  list<string>|null  $tags
     * @param  array<string, mixed>|null  $detail
     */
    public function __construct(
        public ?string $title = null,
        public ?string $subtitle = null,
        public ?string $description = null,
        public ?int $categoryId = null,
        public ?string $level = null,
        public ?string $locale = null,
        public ?string $visibility = null,
        public ?string $completionMode = null,
        public ?string $pricingModel = null,
        public ?int $thumbnailMediaId = null,
        public ?int $introVideoMediaId = null,
        public ?string $introVideoUrl = null,
        public ?array $tags = null,
        public ?array $detail = null,
    ) {}

    /**
     * Only the keys actually supplied. A PATCH that omits `subtitle` must not
     * blank it — the difference between "not sent" and "sent as null" matters.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            title: $input['title'] ?? null,
            subtitle: array_key_exists('subtitle', $input) ? $input['subtitle'] : null,
            description: array_key_exists('description', $input) ? $input['description'] : null,
            categoryId: array_key_exists('category_id', $input) ? $input['category_id'] : null,
            level: $input['level'] ?? null,
            locale: $input['locale'] ?? null,
            visibility: $input['visibility'] ?? null,
            completionMode: $input['completion_mode'] ?? null,
            pricingModel: $input['pricing_model'] ?? null,
            thumbnailMediaId: array_key_exists('thumbnail_media_id', $input) ? $input['thumbnail_media_id'] : null,
            introVideoMediaId: array_key_exists('intro_video_media_id', $input) ? $input['intro_video_media_id'] : null,
            introVideoUrl: array_key_exists('intro_video_url', $input) ? $input['intro_video_url'] : null,
            tags: $input['tags'] ?? null,
            detail: $input['detail'] ?? null,
        );
    }
}
