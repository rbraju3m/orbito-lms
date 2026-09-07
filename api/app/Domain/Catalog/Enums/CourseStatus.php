<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * The course lifecycle, as an explicit state machine rather than a bag of
 * post statuses. Legal transitions live in `canTransitionTo()` so no Action
 * has to remember them.
 */
enum CourseStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InReview => 'In review',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    /** Only a published course is visible in the catalogue or enrollable. */
    public function isLive(): bool
    {
        return $this === self::Published;
    }

    /** Draft and in-review courses may still be edited by their owner. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::InReview;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::InReview, self::Published, self::Archived],
            // A rejected review returns to draft; an approved one publishes.
            self::InReview => [self::Draft, self::Published, self::Archived],
            self::Published => [self::Draft, self::Archived],
            // Archiving is reversible: it is not deletion.
            self::Archived => [self::Draft, self::Published],
        };
    }
}
