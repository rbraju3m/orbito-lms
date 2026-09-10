<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Support;

final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        // Both families: a host whose A record is public and whose AAAA record
        // is ::1 must be refused, because the connection may use either.
        $records = @dns_get_record($host, DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
