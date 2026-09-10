<?php

declare(strict_types=1);

use App\Domain\Webhook\Exceptions\WebhookTargetRefused;
use App\Domain\Webhook\Support\WebhookTarget;
use Tests\Support\FakeHostResolver;

/*
 * The SSRF guard. A webhook is a request our servers make to an address an
 * academy typed in; every case below is an address that request must never
 * reach — or a trick for reaching one.
 */

function vetTarget(string $url, ?FakeHostResolver $dns = null): array
{
    return (new WebhookTarget($dns ?? new FakeHostResolver))->vet($url);
}

it('accepts a public https address and returns the address to pin', function (): void {
    expect(vetTarget('https://hooks.example.com/orbito'))->toBe([
        'host' => 'hooks.example.com',
        'port' => 443,
        'ip' => FakeHostResolver::PUBLIC_IP,
    ]);
});

it('keeps an explicit port', function (): void {
    expect(vetTarget('https://hooks.example.com:8443/in')['port'])->toBe(8443);
});

it('refuses addresses inside the network', function (string $url): void {
    expect(fn () => vetTarget($url))->toThrow(WebhookTargetRefused::class);
})->with([
    'loopback' => 'https://127.0.0.1/hook',
    'private 10/8' => 'https://10.0.0.5/hook',
    'private 192.168/16' => 'https://192.168.1.10/hook',
    'private 172.16/12' => 'https://172.16.4.4/hook',
    'cloud metadata' => 'https://169.254.169.254/latest/meta-data',
    'carrier-grade NAT' => 'https://100.64.0.1/hook',
    'unspecified' => 'https://0.0.0.0/hook',
    'IPv6 loopback' => 'https://[::1]/hook',
    'IPv6 unique-local' => 'https://[fd00::1]/hook',
    'IPv4-mapped IPv6 loopback' => 'https://[::ffff:127.0.0.1]/hook',
]);

it('refuses a host name that resolves inside the network', function (): void {
    $dns = new FakeHostResolver;
    $dns->point('sneaky.example.com', '10.1.2.3');

    expect(fn () => vetTarget('https://sneaky.example.com/hook', $dns))
        ->toThrow(WebhookTargetRefused::class, 'private or internal');
});

/* Which record a connection uses is not ours to choose. */
it('refuses a host when ANY of its addresses is internal', function (): void {
    $dns = new FakeHostResolver;
    $dns->point('split.example.com', FakeHostResolver::PUBLIC_IP, '::1');

    expect(fn () => vetTarget('https://split.example.com/hook', $dns))->toThrow(WebhookTargetRefused::class);
});

it('refuses a host that does not resolve', function (): void {
    $dns = new FakeHostResolver;
    $dns->point('nowhere.example.com');

    expect(fn () => vetTarget('https://nowhere.example.com/hook', $dns))
        ->toThrow(WebhookTargetRefused::class, 'does not resolve');
});

it('refuses plain http', function (): void {
    expect(fn () => vetTarget('http://hooks.example.com/hook'))->toThrow(WebhookTargetRefused::class, 'https');
});

it('refuses credentials in the URL', function (): void {
    expect(fn () => vetTarget('https://user:pass@hooks.example.com/hook'))->toThrow(WebhookTargetRefused::class);
});

/* The developer escape hatch, and why it cannot leak into production. */
it('allows a private address only when configured, and never in production', function (): void {
    config(['orbito.webhooks.allow_private_targets' => true]);

    expect(vetTarget('http://127.0.0.1:9000/hook')['ip'])->toBe('127.0.0.1');

    app()->detectEnvironment(fn () => 'production');

    expect(fn () => vetTarget('https://127.0.0.1/hook'))->toThrow(WebhookTargetRefused::class);
});
