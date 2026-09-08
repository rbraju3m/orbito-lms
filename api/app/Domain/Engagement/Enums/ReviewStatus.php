<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

enum ReviewStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';

    /**
     * Whether this review counts toward the course's rating.
     *
     * The single definition. `RecalculateCourseRating` asks this rather than
     * hardcoding `where('status', 'published')`, so adding a status cannot
     * silently change every course's average.
     */
    public function countsTowardRating(): bool
    {
        return $this === self::Published;
    }

    /** Whether a stranger reading the course page may see it. */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting moderation',
            self::Published => 'Published',
            self::Rejected => 'Rejected',
        };
    }
}
