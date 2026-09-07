<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

enum CourseInstructorRole: string
{
    case Owner = 'owner';
    case CoInstructor = 'co_instructor';
    case Assistant = 'assistant';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::CoInstructor => 'Co-instructor',
            self::Assistant => 'Assistant',
        };
    }
}
