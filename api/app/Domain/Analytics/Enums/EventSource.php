<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Enums;

/**
 * Where an event came from.
 *
 * `Api` is the default and means the SERVER raised it from a domain event.
 * The other two are claims a client makes about itself, so nothing that
 * matters may be decided by this column — it is for splitting traffic in a
 * report, not for trusting anybody.
 */
enum EventSource: string
{
    case Web = 'web';
    case Mobile = 'mobile';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Mobile => 'Mobile',
            self::Api => 'Server',
        };
    }
}
