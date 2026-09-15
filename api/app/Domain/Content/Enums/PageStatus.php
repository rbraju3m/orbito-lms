<?php

declare(strict_types=1);

namespace App\Domain\Content\Enums;

/** A page is built, then published. No schedule: nothing has asked for one. */
enum PageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }
}
