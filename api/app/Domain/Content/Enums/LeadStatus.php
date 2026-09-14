<?php

declare(strict_types=1);

namespace App\Domain\Content\Enums;

/**
 * Where an academy is with somebody who asked to hear from it. A person's
 * bookkeeping, not a lifecycle: every status may follow every other, so there
 * is no transition table to enforce.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Archived => 'Archived',
        };
    }
}
