<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * How the preference matrix is grouped on the settings screen.
 *
 * Somebody who only learns should not have to read past a block of
 * instructor switches to find theirs — and the API says which block a type
 * belongs to rather than the SPA hardcoding a list it would have to keep in
 * step with this enum.
 */
enum NotificationGroup: string
{
    case Learning = 'learning';
    case Teaching = 'teaching';

    public function label(): string
    {
        return match ($this) {
            self::Learning => 'Learning',
            self::Teaching => 'Teaching',
        };
    }
}
