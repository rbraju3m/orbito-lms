<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Assert the documented error envelope (docs/API.md §2), not just the status.
 * A wrong `code` is a breaking API change even when the status is right.
 */
expect()->extend('toBeApiError', function (string $code) {
    /** @var TestResponse $response */
    $response = $this->value;

    $response->assertJsonStructure([
        'error' => ['code', 'message', 'details', 'request_id'],
    ]);

    expect($response->json('error.code'))->toBe($code);

    return $this;
});
