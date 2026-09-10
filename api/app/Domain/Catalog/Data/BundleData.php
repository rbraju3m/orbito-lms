<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

/**
 * Validated bundle attributes crossing into the domain. Actions never see a
 * Request, and nothing here is mass-assigned from raw input.
 */
final readonly class BundleData
{
    /** @param  list<int>|null  $courseIds */
    public function __construct(
        public ?string $title = null,
        public ?string $subtitle = null,
        public ?string $description = null,
        public ?int $thumbnailMediaId = null,
        public ?array $courseIds = null,
    ) {}

    /**
     * Only the keys actually supplied. A PATCH that omits `subtitle` must not
     * blank it — the difference between "not sent" and "sent as null" matters.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        /** @var list<int>|null $courseIds */
        $courseIds = array_key_exists('course_ids', $input)
            ? array_values(array_map(static fn (mixed $id): int => (int) $id, (array) $input['course_ids']))
            : null;

        return new self(
            title: $input['title'] ?? null,
            subtitle: array_key_exists('subtitle', $input) ? $input['subtitle'] : null,
            description: array_key_exists('description', $input) ? $input['description'] : null,
            thumbnailMediaId: array_key_exists('thumbnail_media_id', $input)
                ? $input['thumbnail_media_id']
                : null,
            courseIds: $courseIds,
        );
    }
}
