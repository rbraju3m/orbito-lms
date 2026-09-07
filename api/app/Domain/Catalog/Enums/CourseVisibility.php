<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

enum CourseVisibility: string
{
    /** Listed in the catalogue and indexable. */
    case Public = 'public';
    /** Reachable by direct link, but never listed. */
    case Unlisted = 'unlisted';
    /** Enrolled learners and course staff only. */
    case Private = 'private';

    public function isListed(): bool
    {
        return $this === self::Public;
    }
}
