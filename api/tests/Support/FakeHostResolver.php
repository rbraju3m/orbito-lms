<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Webhook\Support\HostResolver;

/**
 * DNS without the network. Hosts not in the map resolve to one public
 * address (example.com's), so a test only names the hosts it wants to be
 * internal or unresolvable.
 */
final class FakeHostResolver implements HostResolver
{
    public const PUBLIC_IP = '93.184.216.34';

    /** @param  array<string, list<string>>  $hosts */
    public function __construct(private array $hosts = []) {}

    public function point(string $host, string ...$addresses): void
    {
        $this->hosts[$host] = array_values($addresses);
    }

    public function resolve(string $host): array
    {
        return $this->hosts[$host] ?? [self::PUBLIC_IP];
    }
}
