<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * A download's lifecycle — the same three states as a bundle, for the same
 * reason: there is no content to review, so `in_review` would mean nothing.
 */
enum DownloadStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    /** Only a published download is in the catalogue, buyable or claimable. */
    public function isLive(): bool
    {
        return $this === self::Published;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Archived],
            self::Published => [self::Draft, self::Archived],
            // Reversible. Archiving takes it off sale; it does not take it
            // away from anybody who already owns it — see DownloadAccess.
            self::Archived => [self::Draft, self::Published],
        };
    }
}
