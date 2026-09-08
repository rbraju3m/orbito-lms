<?php

declare(strict_types=1);

namespace App\Domain\Live\Enums;

enum WebinarStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Whether it is listed and open to registration. */
    public function isOpen(): bool
    {
        return $this === self::Published;
    }
}
