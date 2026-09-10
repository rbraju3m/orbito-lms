<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * A bundle's lifecycle. Shorter than a course's on purpose: a bundle has no
 * content to review, so there is nothing for `in_review` to mean.
 */
enum BundleStatus: string
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

    /** Only a published bundle is visible in the catalogue or sellable. */
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
            // Archiving is reversible: it is not deletion. Same rule as a
            // course — an archived bundle somebody bought is still an order
            // line that has to make sense.
            self::Archived => [self::Draft, self::Published],
        };
    }
}
