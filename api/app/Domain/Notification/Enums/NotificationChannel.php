<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * How a notification reaches somebody.
 *
 * The value is the string Laravel's notification system routes on, so
 * `via()` can return these directly.
 */
enum NotificationChannel: string
{
    /** The in-app inbox. */
    case Database = 'database';

    /** Email. */
    case Mail = 'mail';

    // Push arrives in P18 as a third case. The preferences table already keys
    // on channel so adding it is a case here and a column of switches there.

    public function label(): string
    {
        return match ($this) {
            self::Database => 'In-app',
            self::Mail => 'Email',
        };
    }

    /**
     * Can somebody switch this channel off?
     *
     * The in-app record cannot be turned off, and that is a deliberate
     * asymmetry. Silencing the inbox does not reduce interruption — it
     * destroys the record of what happened, and leaves "I was never told"
     * with no way to settle it. The thing people actually want quiet is
     * email, so email is what the switches govern.
     */
    public function isLocked(): bool
    {
        return $this === self::Database;
    }

    /** @return list<self> */
    public static function switchable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c) => ! $c->isLocked()));
    }
}
