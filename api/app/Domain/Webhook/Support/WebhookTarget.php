<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Support;

use App\Domain\Webhook\Exceptions\WebhookTargetRefused;

/**
 * The one answer to "may we send a request THERE?"
 *
 * A webhook is a request our servers make to an address an academy typed in.
 * Without this, that is a way to make the platform call its own database, a
 * cloud metadata service (169.254.169.254 hands out credentials), or anything
 * else on the private network, and read the reply back in the delivery log.
 *
 * Asked twice: when an endpoint is saved, so the academy learns at once, and
 * again before EVERY delivery, because a host name can be re-pointed at an
 * internal address after it was approved. The address this returns is the
 * one the delivery then connects to (`CURLOPT_RESOLVE`), so the answer checked
 * is the answer used — no second lookup for DNS rebinding to win.
 *
 * `allow_private_targets` exists for a developer pointing an endpoint at their
 * own machine, and is ignored in production.
 */
final class WebhookTarget
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @return array{host: string, port: int, ip: string}
     *
     * @throws WebhookTargetRefused
     */
    public function vet(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme']) || $parts['host'] === '') {
            throw WebhookTargetRefused::malformed();
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $this->allowsPrivate())) {
            throw WebhookTargetRefused::notHttps();
        }

        // Credentials in a URL end up in logs, and nobody means to send them.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw WebhookTargetRefused::credentialsInUrl();
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolver->resolve($host);

        if ($addresses === []) {
            throw WebhookTargetRefused::unresolvable($host);
        }

        // EVERY address, not the first: which one a connection uses is not
        // ours to choose, so one internal record makes the whole host unsafe.
        foreach ($addresses as $address) {
            if (! $this->isAllowed($address)) {
                throw WebhookTargetRefused::internal($host);
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    private function isAllowed(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($this->allowsPrivate()) {
            return true;
        }

        // ::ffff:127.0.0.1 is 127.0.0.1 wearing an IPv6 costume.
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $m) === 1) {
            $address = $m[1];
        }

        // GLOBAL_RANGE (PHP 8.2+) refuses private, loopback, link-local,
        // carrier-grade NAT, documentation and reserved blocks, both families.
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    private function allowsPrivate(): bool
    {
        return (bool) config('orbito.webhooks.allow_private_targets') && ! app()->isProduction();
    }
}
