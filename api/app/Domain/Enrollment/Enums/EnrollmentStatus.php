<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    /** Whether the learner may still open the course content. */
    public function grantsAccess(): bool
    {
        // Completed still grants access: finishing a course must not lock the
        // learner out of material they paid for.
        return $this === self::Active || $this === self::Completed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'In progress',
            self::Completed => 'Completed',
            self::Expired => 'Expired',
            self::Suspended => 'Suspended',
            self::Cancelled => 'Cancelled',
        };
    }
}
