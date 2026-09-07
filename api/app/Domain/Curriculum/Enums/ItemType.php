<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Enums;

/**
 * Every kind of thing that can sit on the course spine.
 *
 * All cases are declared now even though their entities arrive in later
 * phases, so the spine — and everything hanging off it (progress, drip,
 * ordering) — never changes shape. `isAvailable()` says what can actually be
 * created today; asking for anything else fails loudly rather than silently.
 */
enum ItemType: string
{
    case Lesson = 'lesson';
    case Resource = 'resource';
    case Quiz = 'quiz';
    case Assignment = 'assignment';
    case LiveSession = 'live_session';

    public function label(): string
    {
        return match ($this) {
            self::Lesson => 'Lesson',
            self::Resource => 'Resource',
            self::Quiz => 'Quiz',
            self::Assignment => 'Assignment',
            self::LiveSession => 'Live session',
        };
    }

    public function isAvailable(): bool
    {
        return match ($this) {
            self::Lesson, self::Resource, self::Quiz, self::Assignment => true,
            // Live session: Phase 15.
            self::LiveSession => false,
        };
    }

    /** @return list<self> */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type) => $type->isAvailable()));
    }

    /** Counts toward course completion; a downloadable resource does not. */
    public function isCompletable(): bool
    {
        return $this !== self::Resource;
    }

    /**
     * Whether a learner may declare this item complete themselves.
     *
     * A quiz is completed by submitting an attempt and an assignment by
     * handing work in, not by pressing a button. Otherwise the "mark complete"
     * endpoint would be a way past every graded item in the course — the
     * frontend saying "done" is exactly what must not be trusted.
     */
    public function isSelfMarkable(): bool
    {
        return ! in_array($this, [self::Quiz, self::Assignment], true);
    }
}
