<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Support;

/**
 * Turns a host name into the addresses it points at.
 *
 * An interface only so tests do not depend on the network's DNS: the guard in
 * `WebhookTarget` is the thing under test, and a lookup that fails offline
 * would make every delivery test fail for a reason that is not the code.
 */
interface HostResolver
{
    /** @return list<string> every address, IPv4 and IPv6; empty if none */
    public function resolve(string $host): array;
}
