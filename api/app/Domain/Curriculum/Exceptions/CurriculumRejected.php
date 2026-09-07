<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Exceptions;

use App\Domain\Curriculum\Enums\ItemType;
use App\Support\Exceptions\DomainException;

final class CurriculumRejected extends DomainException
{
    public static function typeUnavailable(ItemType $type): self
    {
        return new self("{$type->label()} items are not available yet.");
    }

    public static function sectionNotInCourse(): self
    {
        return new self('That section does not belong to this course.');
    }

    /**
     * A reorder must be a permutation of what the course currently has.
     * Anything else means the client is working from a stale tree, and applying
     * it would silently drop or duplicate someone's work.
     */
    public static function reorderNotAPermutation(int $expected, int $received): self
    {
        return new self(
            "This reorder does not match the course's current contents "
            ."({$expected} items expected, {$received} received). Reload and try again."
        );
    }

    public function errorCode(): string
    {
        return 'curriculum_rejected';
    }

    public function status(): int
    {
        return 409;
    }
}
