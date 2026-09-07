<?php

declare(strict_types=1);

it('allows the configured SPA origin with credentials', function (): void {
    $response = $this->call('OPTIONS', '/api/v1/health', server: [
        'HTTP_ORIGIN' => 'http://localhost:5173',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:5173')
        ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
});

it('never echoes an unlisted origin back to the caller', function (): void {
    $response = $this->call('OPTIONS', '/api/v1/health', server: [
        'HTTP_ORIGIN' => 'https://evil.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    // The header may be absent or carry the configured origin; what must never
    // happen is the attacker's origin being reflected, which is what the browser
    // compares against.
    expect($response->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('https://evil.example.com');
});

it('never allows a wildcard origin, which credentials would forbid anyway', function (): void {
    expect(config('cors.allowed_origins'))->not->toContain('*');
});
