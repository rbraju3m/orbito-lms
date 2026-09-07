<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum InstructorStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Blocked = 'blocked';

    /** Only an approved instructor may exercise instructor permissions. */
    public function isActive(): bool
    {
        return $this === self::Approved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Blocked => 'Blocked',
        };
    }
}
