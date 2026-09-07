<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';

    /** Whether it may be added to a cart or ordered. */
    public function isSellable(): bool
    {
        return $this === self::Active;
    }
}
