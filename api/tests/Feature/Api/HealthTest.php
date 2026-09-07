<?php

declare(strict_types=1);

use function Pest\Laravel\getJson;

it('reports healthy when every dependency responds', function (): void {
    $response = getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.checks.database.ok', true)
        ->assertJsonPath('data.checks.cache.ok', true)
        ->assertJsonPath('data.checks.queue.ok', true)
        ->assertJsonStructure([
            'data' => ['status', 'app', 'environment', 'version', 'time', 'checks'],
        ]);
});

it('wraps every successful response in a data envelope', function (): void {
    $body = getJson('/api/v1/health')->json();

    expect($body)->toHaveKey('data')
        ->and($body)->not->toHaveKey('error');
});

it('echoes a request id header on every response', function (): void {
    $response = getJson('/api/v1/health');

    expect($response->headers->get('X-Request-Id'))->not->toBeEmpty();
});

it('reuses a well-formed client supplied request id', function (): void {
    $response = getJson('/api/v1/health', ['X-Request-Id' => 'ORBITO-TEST-1234']);

    expect($response->headers->get('X-Request-Id'))->toBe('ORBITO-TEST-1234');
});

it('rejects a malformed client supplied request id and generates its own', function (): void {
    $response = getJson('/api/v1/health', ['X-Request-Id' => 'no spaces allowed <script>']);

    expect($response->headers->get('X-Request-Id'))
        ->not->toBe('no spaces allowed <script>')
        ->not->toBeEmpty();
});
