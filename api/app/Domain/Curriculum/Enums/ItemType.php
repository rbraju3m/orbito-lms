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
            self::Lesson, self::Resource => true,
            // Quiz: Phase 7. Assignment: Phase 8. Live session: Phase 15.
            self::Quiz, self::Assignment, self::LiveSession => false,
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
}
