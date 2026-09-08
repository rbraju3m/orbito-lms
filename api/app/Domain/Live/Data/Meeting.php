<?php

declare(strict_types=1);

namespace App\Domain\Live\Data;

/**
 * What a provider gives back.
 *
 * `hostUrl` is separated from `joinUrl` because on Zoom the two are not
 * interchangeable: the start link opens the meeting AS the host. Handing a
 * learner the wrong one gives them the room.
 */
final class Meeting
{
    public function __construct(
        public readonly ?string $externalId,
        public readonly string $joinUrl,
        public readonly ?string $hostUrl = null,
    ) {}
}
