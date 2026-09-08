<?php

declare(strict_types=1);

namespace App\Domain\Live\Enums;

/**
 * How we know somebody attended.
 *
 * Kept apart because they are different kinds of evidence and a report that
 * cannot tell them apart is a report nobody can defend. A click on Join says
 * they opened the link; a host marking a roster says a person saw them; a
 * provider's own report says the video service counted them in the room.
 */
enum AttendanceSource: string
{
    case SelfJoin = 'self';
    case Host = 'host';
    case Provider = 'provider';

    public function label(): string
    {
        return match ($this) {
            self::SelfJoin => 'Followed the link',
            self::Host => 'Marked by the host',
            self::Provider => 'Reported by the provider',
        };
    }
}
